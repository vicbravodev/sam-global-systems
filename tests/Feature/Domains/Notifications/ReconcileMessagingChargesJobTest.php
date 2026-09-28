<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Channels\TwilioMessenger;
use App\Domains\Notifications\Channels\TwilioVoiceCaller;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Enums\MessagingChargeSource;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Jobs\ReconcileMessagingChargesJob;
use App\Domains\Notifications\Models\MessagingCharge;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;
use Twilio\Exceptions\RestException;

/**
 * The reconciler is the safety net for lost status callbacks and the source
 * of Twilio's REAL price: once a resource is terminal and priced, its cost is
 * metered once into `messaging_cost_micros` (cost-plus billing).
 */
class ReconcileMessagingChargesJobTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    private Team $team;

    /**
     * @var array<string, object>
     */
    private array $messages = [];

    /**
     * @var array<string, object>
     */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->team = Team::factory()->create();

        $messenger = Mockery::mock(TwilioMessenger::class);
        $messenger->shouldReceive('fetchMessage')->andReturnUsing(fn (string $sid) => $this->messages[$sid] ?? throw new RestException('not found', 20404, 404));
        $this->app->instance(TwilioMessenger::class, $messenger);

        $caller = Mockery::mock(TwilioVoiceCaller::class);
        $caller->shouldReceive('fetchCall')->andReturnUsing(fn (string $sid) => $this->calls[$sid] ?? throw new RestException('not found', 20404, 404));
        $this->app->instance(TwilioVoiceCaller::class, $caller);
    }

    public function test_poll_applies_the_status_when_the_callback_was_lost(): void
    {
        $delivery = $this->queuedDelivery(ChannelType::Sms, 'SM_LOST');
        $this->messages['SM_LOST'] = $this->message('delivered', price: '-0.00790');

        $this->runReconciler();

        $fresh = $delivery->fresh();
        $this->assertSame(DeliveryStatus::Delivered, $fresh->status);
        $this->assertSame(NotificationStatus::Sent, $fresh->notification->status);
        $this->assertSame('poll', MessagingCharge::query()->where('provider_sid', 'SM_LOST')->sole()->events_json[0]['source']);
    }

    public function test_real_price_is_metered_once(): void
    {
        $this->queuedDelivery(ChannelType::Sms, 'SM_PRICED');
        $this->messages['SM_PRICED'] = $this->message('delivered', price: '-0.01580', segments: '2');

        $this->runReconciler();
        $this->travel(2)->hours();
        $this->runReconciler();

        $charge = MessagingCharge::query()->where('provider_sid', 'SM_PRICED')->sole();
        $this->assertNotNull($charge->finalized_at);
        $this->assertNotNull($charge->metered_at);
        $this->assertSame(15_800, $charge->price_micros);
        $this->assertSame('USD', $charge->price_unit);
        $this->assertFalse($charge->price_estimated);
        $this->assertSame(2, $charge->segments);

        $events = $this->costEvents();
        $this->assertCount(1, $events);
        $this->assertSame(15_800, (int) $events->first()->quantity);
        $this->assertSame('twilio_charge:SM_PRICED', $events->first()->event_key);
        $this->assertSame($this->team->id, (int) $events->first()->team_id);
    }

    public function test_terminal_without_price_waits_then_is_estimated_after_24_hours(): void
    {
        $this->queuedDelivery(ChannelType::Sms, 'SM_NOPRICE');
        $this->messages['SM_NOPRICE'] = $this->message('delivered', price: null, segments: '1');

        $this->runReconciler();

        $charge = MessagingCharge::query()->where('provider_sid', 'SM_NOPRICE')->sole();
        $this->assertNull($charge->finalized_at);
        $this->assertSame(1, $charge->check_attempts);
        $this->assertTrue($charge->next_check_at->isFuture());

        $this->travel(25)->hours();
        $this->runReconciler();

        $charge->refresh();
        $this->assertNotNull($charge->finalized_at);
        $this->assertTrue($charge->price_estimated);
        $this->assertSame(7_900, $charge->price_micros);
        $this->assertCount(1, $this->costEvents());
    }

    public function test_failed_message_is_finalized_without_cost(): void
    {
        $delivery = $this->queuedDelivery(ChannelType::Sms, 'SM_FAILED');
        $this->messages['SM_FAILED'] = $this->message('failed', price: null, errorCode: 30008);

        $this->runReconciler();

        $charge = MessagingCharge::query()->where('provider_sid', 'SM_FAILED')->sole();
        $this->assertNotNull($charge->finalized_at);
        $this->assertSame(0, $charge->price_micros);
        $this->assertCount(0, $this->costEvents());
        $this->assertSame(DeliveryStatus::Failed, $delivery->fresh()->status);
    }

    public function test_unanswered_call_costs_nothing_and_completed_call_is_priced(): void
    {
        $this->queuedDelivery(ChannelType::Voice, 'CA_NOANSWER');
        $answered = $this->queuedDelivery(ChannelType::Voice, 'CA_TALKED');
        $this->calls['CA_NOANSWER'] = (object) ['status' => 'no-answer', 'duration' => '0', 'price' => null, 'priceUnit' => 'USD'];
        $this->calls['CA_TALKED'] = (object) ['status' => 'completed', 'duration' => '42', 'price' => '-0.01400', 'priceUnit' => 'USD'];

        $this->runReconciler();

        $this->assertSame(0, MessagingCharge::query()->where('provider_sid', 'CA_NOANSWER')->value('price_micros'));
        $this->assertSame(14_000, MessagingCharge::query()->where('provider_sid', 'CA_TALKED')->value('price_micros'));
        $this->assertSame(42, $answered->fresh()->call_duration_seconds);
        $this->assertSame(DeliveryStatus::Delivered, $answered->fresh()->status);
        $this->assertCount(1, $this->costEvents());
    }

    public function test_charges_are_not_polled_before_they_are_due(): void
    {
        $this->queuedDelivery(ChannelType::Sms, 'SM_FRESH');
        MessagingCharge::query()->update(['next_check_at' => now()->addMinute()]);
        $this->messages['SM_FRESH'] = $this->message('delivered', price: '-0.00790');

        $this->runReconciler();

        $this->assertNull(MessagingCharge::query()->where('provider_sid', 'SM_FRESH')->value('finalized_at'));
    }

    public function test_otp_and_verification_charges_are_priced_too(): void
    {
        MessagingCharge::factory()->create([
            'team_id' => $this->team->id,
            'provider_sid' => 'SM_OTP',
            'source_type' => MessagingChargeSource::Otp,
        ]);
        MessagingCharge::factory()->call()->create([
            'team_id' => $this->team->id,
            'provider_sid' => 'CA_VERIFY',
            'source_type' => MessagingChargeSource::VerificationCall,
        ]);
        $this->messages['SM_OTP'] = $this->message('delivered', price: '-0.00790');
        $this->calls['CA_VERIFY'] = (object) ['status' => 'completed', 'duration' => '20', 'price' => '-0.01400', 'priceUnit' => 'USD'];

        $this->runReconciler();

        $this->assertSame(21_900, (int) $this->costEvents()->sum('quantity'));
    }

    public function test_unknown_sid_at_twilio_is_closed_without_cost(): void
    {
        $this->queuedDelivery(ChannelType::Sms, 'SM_GONE');

        $this->runReconciler();

        $charge = MessagingCharge::query()->where('provider_sid', 'SM_GONE')->sole();
        $this->assertNotNull($charge->finalized_at);
        $this->assertSame(0, $charge->price_micros);
    }

    public function test_reconciler_writes_each_charge_only_into_its_own_tenant(): void
    {
        $other = Team::factory()->create();
        $mine = $this->queuedDelivery(ChannelType::Sms, 'SM_MINE');
        $theirs = $this->queuedDelivery(ChannelType::Sms, 'SM_THEIRS', $other);
        $this->messages['SM_MINE'] = $this->message('delivered', price: '-0.00790');
        $this->messages['SM_THEIRS'] = $this->message('undelivered', price: '-0.00790', errorCode: 30003);

        $this->runReconciler();

        $this->assertSame(DeliveryStatus::Delivered, $mine->fresh()->status);
        $this->assertSame(DeliveryStatus::Failed, $theirs->fresh()->status);

        $mineEvent = $this->costEvents()->firstWhere('event_key', 'twilio_charge:SM_MINE');
        $theirEvent = $this->costEvents()->firstWhere('event_key', 'twilio_charge:SM_THEIRS');
        $this->assertSame($this->team->id, (int) $mineEvent->team_id);
        $this->assertSame($other->id, (int) $theirEvent->team_id);

        // Applying a status for this tenant's SID never writes the other's rows.
        $this->queuedDelivery(ChannelType::Sms, 'SM_MINE_2');
        $this->messages['SM_MINE_2'] = $this->message('delivered', price: '-0.00790');
        $this->assertNoTenantLeak($this->team, fn () => $this->runReconciler());
    }

    private function runReconciler(): void
    {
        app()->call([new ReconcileMessagingChargesJob, 'handle']);
    }

    private function message(string $status, ?string $price, string $segments = '1', ?int $errorCode = null): object
    {
        return (object) [
            'status' => $status,
            'errorCode' => $errorCode,
            'numSegments' => $segments,
            'price' => $price,
            'priceUnit' => 'USD',
        ];
    }

    /**
     * @return Collection<int, UsageEvent>
     */
    private function costEvents()
    {
        $meterId = UsageMeter::query()->where('code', 'messaging_cost_micros')->value('id');

        return UsageEvent::withoutGlobalScopes()->where('usage_meter_id', $meterId)->get();
    }

    private function queuedDelivery(ChannelType $type, string $sid, ?Team $team = null): NotificationDelivery
    {
        $team ??= $this->team;

        $channel = NotificationChannel::query()->where('channel_type', $type)->first()
            ?? NotificationChannel::factory()->create(['channel_type' => $type, 'provider' => 'twilio']);

        $notification = Notification::factory()->create(['team_id' => $team->id, 'status' => NotificationStatus::Queued]);
        $recipient = NotificationRecipient::factory()->create([
            'notification_id' => $notification->id,
            'team_id' => $team->id,
            'phone' => '+5215512345678',
        ]);

        $delivery = NotificationDelivery::factory()->create([
            'notification_id' => $notification->id,
            'recipient_id' => $recipient->id,
            'channel_id' => $channel->id,
            'team_id' => $team->id,
            'status' => DeliveryStatus::Queued,
            'provider_message_id' => $sid,
            'accepted_at' => now(),
        ]);

        MessagingCharge::factory()->forDelivery($delivery)->create(['next_check_at' => now()->subMinute()]);

        return $delivery;
    }
}
