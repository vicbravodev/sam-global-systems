<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Actions\FinalizeMessagingCharge;
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
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Concerns\AssertsSystemLog;
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
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private const RECIPIENT_DIGITS = '5215512345678';

    private Team $team;

    /**
     * @var array<string, object>
     */
    private array $messages = [];

    /**
     * @var array<string, object>
     */
    private array $calls = [];

    /**
     * SIDs whose fetch fails with a Twilio error other than 20404.
     *
     * @var array<string, int>
     */
    private array $providerErrors = [];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->team = Team::factory()->create();

        $messenger = Mockery::mock(TwilioMessenger::class);
        $messenger->shouldReceive('fetchMessage')->andReturnUsing(fn (string $sid) => isset($this->providerErrors[$sid])
            ? throw new RestException('boom', $this->providerErrors[$sid], 500)
            : ($this->messages[$sid] ?? throw new RestException('not found', 20404, 404)));
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
        $finalizedAfterFirstRun = count($this->systemLogEntries('billing.messaging_charge.finalized'));
        $this->travel(2)->hours();
        $this->runReconciler();
        $this->assertSame($finalizedAfterFirstRun, count($this->systemLogEntries('billing.messaging_charge.finalized')), 'the second run must not finalize again');

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

        $c = $this->assertSystemLogged('billing.messaging_charge.finalized', fn (array $c) => $c['outcome'] === 'ok');
        $this->assertSame(1, $finalizedAfterFirstRun);
        $this->assertSame($this->team->id, $c['input']['team_id']);
        $this->assertSame($charge->id, $c['input']['charge_id']);
        $this->assertSame('SM_PRICED', $c['input']['provider_sid']);
        $this->assertSame('message', $c['input']['resource_type']);
        $this->assertSame('sms', $c['input']['channel_type']);
        $this->assertSame('provider', $c['calc']['price_source']);
        $this->assertSame('-0.01580', $c['calc']['provider_price']);
        $this->assertSame('USD', $c['calc']['price_unit']);
        $this->assertSame('price_micros = round(abs(provider_price) * 1e6)', $c['calc']['formula']);
        $recomputed = (int) round(abs((float) $c['calc']['provider_price']) * 1_000_000);
        $this->assertSame($recomputed, $c['calc']['price_micros']);
        $this->assertSame($charge->price_micros, $c['calc']['price_micros']);
        $this->assertFalse($c['calc']['estimated']);
        $this->assertTrue($c['result']['metered']);
        $this->assertSame('messaging_cost_micros', $c['result']['meter_code']);
        $this->assertSame('twilio_charge:SM_PRICED', $c['result']['event_key']);

        $this->assertSystemLogged('billing.messaging_charge.reconciled', fn (array $c) => $c['calc']['branch'] === 'priced'
            && $c['calc']['provider_status'] === 'delivered'
            && $c['calc']['terminal'] === true
            && $c['calc']['price_present'] === true
            && $c['calc']['estimate_after_hours'] === 24
            && $c['calc']['give_up_after_hours'] === 72);

        $this->assertLogsAreClean();
    }

    public function test_the_brief_example_price_is_recomputable_from_the_log(): void
    {
        $this->queuedDelivery(ChannelType::Sms, 'SM_BRIEF');
        $this->messages['SM_BRIEF'] = $this->message('delivered', price: '-0.00790');

        $this->runReconciler();

        $charge = MessagingCharge::query()->where('provider_sid', 'SM_BRIEF')->sole();
        $c = $this->assertSystemLogged('billing.messaging_charge.finalized', fn (array $c) => $c['calc']['price_source'] === 'provider');
        $this->assertSame('-0.00790', $c['calc']['provider_price']);
        $this->assertSame((int) round(abs((float) '-0.00790') * 1_000_000), $c['calc']['price_micros']);
        $this->assertSame($charge->price_micros, $c['calc']['price_micros']);
        $this->assertTrue($c['result']['metered']);
        $this->assertLogsAreClean();
    }

    public function test_terminal_without_price_waits_then_is_estimated_after_24_hours(): void
    {
        $this->freezeTime();
        $this->queuedDelivery(ChannelType::Sms, 'SM_NOPRICE');
        $this->messages['SM_NOPRICE'] = $this->message('delivered', price: null, segments: '1');

        $this->runReconciler();

        $charge = MessagingCharge::query()->where('provider_sid', 'SM_NOPRICE')->sole();
        $this->assertNull($charge->finalized_at);
        $this->assertSame(1, $charge->check_attempts);
        $this->assertTrue($charge->next_check_at->isFuture());

        $wait = $this->systemLogEntries('billing.messaging_charge.reconciled')[0];
        $this->assertSame('debug', $wait['level']);
        $w = $wait['context'];
        $this->assertSame('rescheduled', $w['calc']['branch']);
        $this->assertTrue($w['calc']['terminal']);
        $this->assertFalse($w['calc']['price_present']);
        $this->assertSame(0, $w['calc']['age_hours']);
        $this->assertSame(1, $w['result']['check_attempts']);
        $this->assertSame('delay_minutes = min(60, 2 ** min(check_attempts, 6))', $w['result']['formula']);
        $this->assertSame(min(60, 2 ** min($w['result']['check_attempts'], 6)), $w['result']['delay_minutes']);
        $this->assertSame($charge->check_attempts, $w['result']['check_attempts']);
        $this->assertSame($charge->next_check_at->toIso8601String(), $w['result']['next_check_at']);
        $this->assertSame(
            $charge->last_checked_at->copy()->addMinutes($w['result']['delay_minutes'])->toIso8601String(),
            $charge->next_check_at->toIso8601String(),
        );

        $this->travel(25)->hours();
        $this->runReconciler();

        $charge->refresh();
        $this->assertNotNull($charge->finalized_at);
        $this->assertTrue($charge->price_estimated);
        $this->assertSame(7_900, $charge->price_micros);
        $this->assertCount(1, $this->costEvents());

        $this->assertSystemLogged('billing.messaging_charge.reconciled', fn (array $c) => $c['calc']['branch'] === 'estimated_after_hours'
            && $c['calc']['age_hours'] === 25);
        $c = $this->assertSystemLogged('billing.messaging_charge.finalized', fn (array $c) => $c['outcome'] === 'ok');
        $this->assertSame('estimate', $c['calc']['price_source']);
        $this->assertSame('sms_segment', $c['calc']['estimate_unit']);
        $this->assertSame(1, $c['calc']['units']);
        $this->assertSame((float) config('services.twilio.estimated_prices.sms_segment'), $c['calc']['unit_price_usd']);
        $this->assertSame(
            (int) round($c['calc']['unit_price_usd'] * $c['calc']['units'] * 1_000_000),
            $c['calc']['price_micros'],
        );
        $this->assertSame($charge->price_micros, $c['calc']['price_micros']);
        $this->assertTrue($c['calc']['estimated']);
        $this->assertTrue($c['result']['metered']);
        $this->assertLogsAreClean();
    }

    public function test_a_call_estimate_rounds_the_minutes_up(): void
    {
        $delivery = $this->queuedDelivery(ChannelType::Voice, 'CA_NOPRICE');
        $this->calls['CA_NOPRICE'] = (object) ['status' => 'completed', 'duration' => '61', 'price' => null, 'priceUnit' => 'USD'];

        $this->travel(25)->hours();
        $this->runReconciler();

        $charge = MessagingCharge::query()->where('provider_sid', 'CA_NOPRICE')->sole();
        $c = $this->assertSystemLogged('billing.messaging_charge.finalized', fn (array $c) => $c['calc']['price_source'] === 'estimate');
        $this->assertSame('voice_minute', $c['calc']['estimate_unit']);
        $this->assertSame(61, $c['calc']['duration_seconds']);
        $this->assertSame(max(1, (int) ceil($c['calc']['duration_seconds'] / 60)), $c['calc']['units']);
        $this->assertSame(2, $c['calc']['units']);
        $this->assertSame((int) round($c['calc']['unit_price_usd'] * $c['calc']['units'] * 1_000_000), $c['calc']['price_micros']);
        $this->assertSame($charge->price_micros, $c['calc']['price_micros']);
        $this->assertSame('voice', $c['input']['channel_type']);
        $this->assertSame('call', $c['input']['resource_type']);
        $this->assertLogsAreClean();
    }

    public function test_a_resource_stuck_past_the_give_up_window_is_estimated(): void
    {
        $this->queuedDelivery(ChannelType::Whatsapp, 'SM_STUCK');
        $this->messages['SM_STUCK'] = $this->message('sent', price: null);

        $this->travel(73)->hours();
        $this->runReconciler();

        $this->assertSystemLogged('billing.messaging_charge.reconciled', fn (array $c) => $c['calc']['branch'] === 'gave_up_estimated'
            && $c['calc']['terminal'] === false
            && $c['calc']['age_hours'] === 73);
        $c = $this->assertSystemLogged('billing.messaging_charge.finalized', fn (array $c) => $c['calc']['price_source'] === 'estimate');
        $this->assertSame('whatsapp_message', $c['calc']['estimate_unit']);
        $this->assertSame(1, $c['calc']['units']);
        $this->assertSame((int) round($c['calc']['unit_price_usd'] * $c['calc']['units'] * 1_000_000), $c['calc']['price_micros']);
        $this->assertSame(MessagingCharge::query()->where('provider_sid', 'SM_STUCK')->value('price_micros'), $c['calc']['price_micros']);
        $this->assertLogsAreClean();
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

        $this->assertSystemLogged('billing.messaging_charge.reconciled', fn (array $c) => $c['calc']['branch'] === 'free_status'
            && $c['calc']['provider_status'] === 'failed');
        $c = $this->assertSystemLogged('billing.messaging_charge.finalized', fn (array $c) => $c['outcome'] === 'ok');
        $this->assertSame('free', $c['calc']['price_source']);
        $this->assertSame(0, $c['calc']['price_micros']);
        $this->assertFalse($c['result']['metered']);
        $this->assertSame('zero_cost', $c['result']['meter_skipped_reason']);
        $this->assertLogsAreClean();
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

        $c = $this->assertSystemLogged('billing.messaging_charge.reconciled', fn (array $c) => $c['calc']['branch'] === 'not_found_at_provider');
        $this->assertSame(20404, $c['calc']['provider_error_code']);
        $this->assertSame($charge->id, $c['input']['charge_id']);
        $this->assertSystemLogged('billing.messaging_charge.finalized', fn (array $c) => $c['calc']['price_source'] === 'free'
            && $c['result']['meter_skipped_reason'] === 'zero_cost');
        $this->assertLogsAreClean();
    }

    public function test_a_provider_error_reschedules_and_logs_the_retry(): void
    {
        $this->queuedDelivery(ChannelType::Sms, 'SM_DOWN');
        $this->providerErrors['SM_DOWN'] = 20500;

        $this->runReconciler();

        $charge = MessagingCharge::query()->where('provider_sid', 'SM_DOWN')->sole();
        $this->assertNull($charge->finalized_at);

        $c = $this->assertSystemLogged('billing.messaging_charge.reconcile_failed', fn (array $c) => $c['reason'] === 'provider_error');
        $this->assertSame($this->team->id, $c['input']['team_id']);
        $this->assertSame($charge->id, $c['input']['charge_id']);
        $this->assertSame('RestException', $c['input']['error_class']);
        $this->assertSame(20500, $c['input']['provider_error_code']);
        $this->assertArrayHasKey('error', $c);
        $this->assertSame(1, $c['calc']['check_attempts']);
        $this->assertSame(min(60, 2 ** min($c['calc']['check_attempts'], 6)), $c['calc']['delay_minutes']);
        $this->assertSame($charge->check_attempts, $c['calc']['check_attempts']);
        $this->assertSame($charge->next_check_at->toIso8601String(), $c['calc']['next_check_at']);
        $this->assertSystemNotLogged('billing.messaging_charge.reconciled');

        $done = $this->assertSystemLogged('billing.messaging_reconcile.completed');
        $this->assertSame(1, $done['result']['charges_due_count']);
        $this->assertSame(1, $done['result']['charges_processed_count']);
        $this->assertSame(1, $done['result']['charges_failed_count']);
        $this->assertFalse($done['result']['budget_exhausted']);
        $this->assertLogsAreClean();
    }

    public function test_a_charge_whose_meter_is_missing_is_finalized_but_logged_as_not_metered(): void
    {
        $this->queuedDelivery(ChannelType::Sms, 'SM_NOMETER');
        $this->messages['SM_NOMETER'] = $this->message('delivered', price: '-0.00790');
        UsageMeter::query()->where('code', 'messaging_cost_micros')->delete();

        $this->runReconciler();

        $charge = MessagingCharge::query()->where('provider_sid', 'SM_NOMETER')->sole();
        $this->assertNotNull($charge->finalized_at);
        $this->assertNull($charge->metered_at);

        $this->assertSystemLogged('billing.messaging_usage.not_metered', fn (array $c) => $c['reason'] === 'record_failed'
            && $c['input']['event_key'] === 'twilio_charge:SM_NOMETER');
        $c = $this->assertSystemLogged('billing.messaging_charge.finalized', fn (array $c) => $c['outcome'] === 'degraded');
        $this->assertSame('not_metered', $c['reason']);
        $this->assertSame('provider', $c['calc']['price_source']);
        $this->assertSame(7_900, $c['calc']['price_micros']);
        $this->assertSame(['metered' => false, 'finalized' => true], $c['result']);
        $this->assertSame(0, count(array_filter(
            $this->systemLogEntries('billing.messaging_charge.finalized'),
            fn (array $e) => $e['context']['outcome'] === 'ok',
        )));
        $this->assertLogsAreClean();
    }

    public function test_an_already_finalized_charge_is_skipped(): void
    {
        $charge = MessagingCharge::factory()->create([
            'team_id' => $this->team->id,
            'provider_sid' => 'SM_DONE',
            'finalized_at' => now(),
            'price_micros' => 100,
        ]);

        app(FinalizeMessagingCharge::class)->withProviderPrice($charge, '-0.00790', 'USD');

        $this->assertSame(100, $charge->fresh()->price_micros);
        $c = $this->assertSystemLogged('billing.messaging_charge.finalized', fn (array $c) => $c['outcome'] === 'skipped');
        $this->assertSame('already_finalized', $c['reason']);
        $this->assertSame($charge->id, $c['input']['charge_id']);
        $this->assertSame($this->team->id, $c['input']['team_id']);
        $this->assertLogsAreClean();
    }

    public function test_reconciler_writes_each_charge_only_into_its_own_tenant(): void
    {
        $other = Team::factory()->create();
        $mine = $this->queuedDelivery(ChannelType::Sms, 'SM_MINE');
        $theirs = $this->queuedDelivery(ChannelType::Sms, 'SM_THEIRS', $other);
        $tenantAtEmission = [];
        SystemLog::listen(function (array $entry) use (&$tenantAtEmission): void {
            $tenantAtEmission[] = [$entry['code'], TenantContext::id()];
        });
        $this->messages['SM_MINE'] = $this->message('delivered', price: '-0.00790');
        $this->messages['SM_THEIRS'] = $this->message('undelivered', price: '-0.00790', errorCode: 30003);

        $this->runReconciler();

        $this->assertSame(DeliveryStatus::Delivered, $mine->fresh()->status);
        $this->assertSame(DeliveryStatus::Failed, $theirs->fresh()->status);

        $mineEvent = $this->costEvents()->firstWhere('event_key', 'twilio_charge:SM_MINE');
        $theirEvent = $this->costEvents()->firstWhere('event_key', 'twilio_charge:SM_THEIRS');
        $this->assertSame($this->team->id, (int) $mineEvent->team_id);
        $this->assertSame($other->id, (int) $theirEvent->team_id);

        $teamByCharge = MessagingCharge::withoutGlobalScopes()->pluck('team_id', 'id')->map(fn ($id) => (int) $id);
        $chargeLines = array_filter($this->systemLogEntries(), fn (array $e) => str_starts_with($e['code'], 'billing.messaging_charge.'));
        $this->assertNotEmpty($chargeLines);
        $seenTeams = [];
        foreach ($chargeLines as $entry) {
            $input = $entry['context']['input'];
            $this->assertSame($teamByCharge[$input['charge_id']], $input['team_id'], "[{$entry['code']}] lleva el team de otro cargo");
            $seenTeams[$input['team_id']] = true;
            $json = json_encode($entry['context']);
            $foreign = $input['team_id'] === $this->team->id ? 'SM_THEIRS' : 'SM_MINE';
            $this->assertStringNotContainsString($foreign, $json);
        }
        $this->assertEqualsCanonicalizing([$this->team->id, $other->id], array_keys($seenTeams));

        foreach ($tenantAtEmission as [$code, $tenantId]) {
            if (str_starts_with($code, 'billing.messaging_charge.')) {
                $this->assertNotNull($tenantId, "[{$code}] se emitió fuera del contexto de tenant");
            }
        }
        $chargeLinesAtEmission = array_values(array_filter($tenantAtEmission, fn (array $e) => str_starts_with($e[0], 'billing.messaging_charge.')));
        foreach (array_values($chargeLines) as $i => $entry) {
            $this->assertSame($entry['context']['input']['team_id'], $chargeLinesAtEmission[$i][1]);
        }

        $done = $this->assertSystemLogged('billing.messaging_reconcile.completed');
        $this->assertNull(collect($tenantAtEmission)->firstWhere(0, 'billing.messaging_reconcile.completed')[1]);
        $this->assertArrayNotHasKey('input', $done);
        $this->assertStringNotContainsString('team_id', json_encode($done));
        $this->assertStringNotContainsString('charge_id', json_encode($done));
        $this->assertStringNotContainsString('SM_', json_encode($done));
        $this->assertSame(2, $done['result']['charges_due_count']);
        $this->assertSame(2, $done['result']['charges_processed_count']);
        $this->assertSame(0, $done['result']['charges_failed_count']);
        $this->assertSame(2, $done['result']['branch_counts']['priced_count']);
        $this->assertSame(200, $done['calc']['batch_size']);
        $this->assertSame(180, $done['calc']['time_budget_seconds']);
        $this->assertLogsAreClean();

        // Applying a status for this tenant's SID never writes the other's rows.
        $this->queuedDelivery(ChannelType::Sms, 'SM_MINE_2');
        $this->messages['SM_MINE_2'] = $this->message('delivered', price: '-0.00790');
        $this->assertNoTenantLeak($this->team, fn () => $this->runReconciler());
    }

    private function assertLogsAreClean(): void
    {
        $this->assertNoSensitiveDataLogged();
        $this->assertStringNotContainsString(self::RECIPIENT_DIGITS, (string) json_encode($this->systemLogEntries()));
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
            'phone' => '+'.self::RECIPIENT_DIGITS,
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
