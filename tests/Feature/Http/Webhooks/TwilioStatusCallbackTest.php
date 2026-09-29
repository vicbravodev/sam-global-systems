<?php

namespace Tests\Feature\Http\Webhooks;

use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Events\NotificationDelivered;
use App\Domains\Notifications\Jobs\FallbackNotificationChannelJob;
use App\Domains\Notifications\Jobs\RetryNotificationDeliveryJob;
use App\Domains\Notifications\Models\MessagingCharge;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * Twilio status callbacks for notification messages and calls
 * (`POST /api/webhooks/twilio/status`): the only way SAM learns whether an
 * SMS/WhatsApp was delivered or a voice notification was answered.
 */
class TwilioStatusCallbackTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private const AUTH_TOKEN = 'platform-status-token';

    private const PATH = '/api/webhooks/twilio/status';

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.twilio.account_sid', 'AC_PLATFORM');
        config()->set('services.twilio.auth_token', self::AUTH_TOKEN);

        $this->team = Team::factory()->create();
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $delivery = $this->queuedDelivery(ChannelType::Sms, 'SM_SIG');

        $this->post(self::PATH, ['MessageSid' => 'SM_SIG', 'MessageStatus' => 'delivered'], ['X-Twilio-Signature' => 'forged'])
            ->assertForbidden();

        $this->assertSame(DeliveryStatus::Queued, $delivery->fresh()->status);
    }

    public function test_unknown_sid_answers_200_without_effects(): void
    {
        $delivery = $this->queuedDelivery(ChannelType::Sms, 'SM_KNOWN');

        $this->postStatus(['MessageSid' => 'SM_UNKNOWN', 'MessageStatus' => 'delivered'])
            ->assertOk()
            ->assertContent('');

        $this->assertSame(DeliveryStatus::Queued, $delivery->fresh()->status);

        $this->assertSystemLogged('notifications.provider_status.skipped', fn (array $c) => $c['reason'] === 'unknown_sid');
        $this->assertSystemNotLogged('notifications.provider_status.applied');
        $this->assertStringNotContainsString('SM_UNKNOWN', json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_callback_for_a_deleted_delivery_only_updates_the_charge(): void
    {
        $delivery = $this->queuedDelivery(ChannelType::Sms, 'SM_GONE');
        $charge = MessagingCharge::withoutGlobalScopes()->where('provider_sid', 'SM_GONE')->sole();
        NotificationDelivery::withoutGlobalScopes()->whereKey($delivery->id)->delete();

        $this->postStatus(['MessageSid' => 'SM_GONE', 'MessageStatus' => 'delivered'])->assertOk()->assertContent('');

        $this->assertSame('delivered', $charge->fresh()->status);

        $entries = array_values(array_filter(
            $this->systemLogEntries('notifications.provider_status.skipped'),
            fn (array $e) => $e['context']['reason'] === 'delivery_missing',
        ));
        $this->assertCount(1, $entries);
        $this->assertSame('info', $entries[0]['level']);
        $this->assertSame(['charge_id' => $charge->id, 'source' => 'callback', 'resource_type' => 'message', 'delivery_id' => null], $entries[0]['context']['input']);
        $this->assertSystemNotLogged('notifications.provider_status.applied');
        $this->assertSame([], array_filter(
            $this->systemLogEntries('notifications.provider_status.skipped'),
            fn (array $e) => $e['context']['reason'] === 'superseded_attempt',
        ));
        $this->assertStringNotContainsString('SM_GONE', json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_callback_without_status_is_skipped_as_missing_fields(): void
    {
        $this->postStatus(['MessageSid' => 'SM_EMPTY'])->assertOk()->assertContent('');

        $this->assertSystemLogged('notifications.provider_status.skipped', fn (array $c) => $c['reason'] === 'missing_fields'
            && $c['calc'] === ['sid_present' => true, 'status_present' => false]);
        $this->assertStringNotContainsString('SM_EMPTY', json_encode($this->systemLogEntries()));
    }

    public function test_message_progresses_queued_sent_delivered(): void
    {
        Event::fake([NotificationDelivered::class]);
        $delivery = $this->queuedDelivery(ChannelType::Sms, 'SM_FLOW');

        $this->postStatus(['MessageSid' => 'SM_FLOW', 'MessageStatus' => 'sent'])->assertOk();
        $this->assertSame(DeliveryStatus::Sent, $delivery->fresh()->status);

        $this->postStatus(['MessageSid' => 'SM_FLOW', 'MessageStatus' => 'delivered'])->assertOk();

        $fresh = $delivery->fresh();
        $this->assertSame(DeliveryStatus::Delivered, $fresh->status);
        $this->assertNotNull($fresh->delivered_at);
        $this->assertSame('delivered', $fresh->provider_status);
        $this->assertSame(NotificationStatus::Sent, $fresh->notification->status);

        $charge = MessagingCharge::withoutGlobalScopes()->where('provider_sid', 'SM_FLOW')->sole();
        $this->assertSame(['sent', 'delivered'], array_column($charge->events_json, 'status'));
        Event::assertDispatchedTimes(NotificationDelivered::class, 1);

        $base = ['charge_id' => $charge->id, 'source' => 'callback', 'resource_type' => 'message', 'delivery_id' => $delivery->id];
        $this->assertSystemLogged('notifications.provider_status.applied', fn (array $c) => $c['input'] === $base
            && $c['calc']['provider_status'] === 'sent'
            && $c['result'] === ['from_status' => 'queued', 'to_status' => 'sent', 'permanent' => null]);
        $this->assertSystemLogged('notifications.provider_status.applied', fn (array $c) => $c['input'] === $base
            && $c['calc']['provider_status'] === 'delivered'
            && $c['calc']['provider_error_code'] === null
            && $c['result'] === ['from_status' => 'sent', 'to_status' => 'delivered', 'permanent' => null]);
        $this->assertStringNotContainsString('5215512345678', json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_out_of_order_callback_never_moves_a_delivery_backwards(): void
    {
        $delivery = $this->queuedDelivery(ChannelType::Sms, 'SM_ORDER');

        $this->postStatus(['MessageSid' => 'SM_ORDER', 'MessageStatus' => 'delivered']);
        $this->postStatus(['MessageSid' => 'SM_ORDER', 'MessageStatus' => 'sent']);
        $this->postStatus(['MessageSid' => 'SM_ORDER', 'MessageStatus' => 'queued']);

        $fresh = $delivery->fresh();
        $this->assertSame(DeliveryStatus::Delivered, $fresh->status);
        $this->assertSame('delivered', $fresh->provider_status);

        $this->assertCount(1, $this->systemLogEntries('notifications.provider_status.applied'));
        $skipped = array_values(array_filter(
            $this->systemLogEntries('notifications.provider_status.skipped'),
            fn (array $e) => $e['context']['reason'] === 'not_advancing',
        ));
        $this->assertCount(2, $skipped);
        $this->assertSame(['debug', 'debug'], array_column($skipped, 'level'));
        $this->assertSame('delivered', $skipped[0]['context']['result']['from_status']);
        $this->assertSame('sent', $skipped[0]['context']['result']['to_status']);
    }

    public function test_whatsapp_read_marks_read_at(): void
    {
        $delivery = $this->queuedDelivery(ChannelType::Whatsapp, 'SM_READ');

        $this->postStatus(['MessageSid' => 'SM_READ', 'MessageStatus' => 'delivered']);
        $this->postStatus(['MessageSid' => 'SM_READ', 'MessageStatus' => 'read']);

        $fresh = $delivery->fresh();
        $this->assertSame(DeliveryStatus::Delivered, $fresh->status);
        $this->assertNotNull($fresh->read_at);
    }

    public function test_repeated_callback_is_idempotent(): void
    {
        Event::fake([NotificationDelivered::class]);
        $delivery = $this->queuedDelivery(ChannelType::Sms, 'SM_DUP');

        $this->postStatus(['MessageSid' => 'SM_DUP', 'MessageStatus' => 'delivered']);
        $deliveredAt = $delivery->fresh()->delivered_at;

        $this->travel(5)->minutes();
        $this->postStatus(['MessageSid' => 'SM_DUP', 'MessageStatus' => 'delivered']);

        $this->assertEquals($deliveredAt, $delivery->fresh()->delivered_at);
        $this->assertCount(1, MessagingCharge::withoutGlobalScopes()->where('provider_sid', 'SM_DUP')->sole()->events_json);
        Event::assertDispatchedTimes(NotificationDelivered::class, 1);
    }

    public function test_transient_undelivered_triggers_a_retry(): void
    {
        Queue::fake();
        $delivery = $this->queuedDelivery(ChannelType::Sms, 'SM_TRANSIENT');

        $this->postStatus(['MessageSid' => 'SM_TRANSIENT', 'MessageStatus' => 'undelivered', 'ErrorCode' => '30003']);

        $fresh = $delivery->fresh();
        $this->assertSame(DeliveryStatus::Failed, $fresh->status);
        $this->assertSame('30003', $fresh->provider_error_code);
        $this->assertFalse($fresh->permanent_failure);
        $this->assertSame(NotificationStatus::Failed, $fresh->notification->status);

        Queue::assertPushed(RetryNotificationDeliveryJob::class, fn ($job) => $job->deliveryId === $delivery->id);
        Queue::assertNotPushed(FallbackNotificationChannelJob::class);
    }

    public function test_permanent_failure_goes_to_fallback_without_retrying(): void
    {
        Queue::fake();
        $delivery = $this->queuedDelivery(ChannelType::Sms, 'SM_PERMANENT');

        $this->postStatus(['MessageSid' => 'SM_PERMANENT', 'MessageStatus' => 'undelivered', 'ErrorCode' => '30005']);

        $this->assertTrue($delivery->fresh()->permanent_failure);
        Queue::assertNotPushed(RetryNotificationDeliveryJob::class);
        Queue::assertPushed(FallbackNotificationChannelJob::class, fn ($job) => $job->failedDeliveryId === $delivery->id);

        $this->assertSystemLogged('notifications.provider_status.applied', fn (array $c) => $c['input']['delivery_id'] === $delivery->id
            && $c['calc']['provider_status'] === 'undelivered'
            && $c['calc']['provider_error_code'] === '30005'
            && $c['result']['to_status'] === 'failed'
            && $c['result']['permanent'] === true);
        $this->assertSystemLogged('notifications.fallback.requested', fn (array $c) => $c['calc']['trigger'] === 'permanent_failure');
    }

    public function test_answered_voice_call_is_delivered_with_its_duration(): void
    {
        $delivery = $this->queuedDelivery(ChannelType::Voice, 'CA_ANSWERED');

        $this->postStatus(['CallSid' => 'CA_ANSWERED', 'CallStatus' => 'ringing']);
        $this->assertSame(DeliveryStatus::Sending, $delivery->fresh()->status);

        $this->postStatus(['CallSid' => 'CA_ANSWERED', 'CallStatus' => 'in-progress']);
        $this->assertSame(DeliveryStatus::Delivered, $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->answered_at);

        $this->postStatus(['CallSid' => 'CA_ANSWERED', 'CallStatus' => 'completed', 'CallDuration' => '42']);

        $fresh = $delivery->fresh();
        $this->assertSame(DeliveryStatus::Delivered, $fresh->status);
        $this->assertSame(42, $fresh->call_duration_seconds);
        $this->assertSame('completed', $fresh->provider_status);
    }

    public function test_completed_call_with_talk_time_counts_as_answered_even_if_answered_event_was_lost(): void
    {
        $delivery = $this->queuedDelivery(ChannelType::Voice, 'CA_LATE');

        $this->postStatus(['CallSid' => 'CA_LATE', 'CallStatus' => 'completed', 'CallDuration' => '15']);

        $this->assertSame(DeliveryStatus::Delivered, $delivery->fresh()->status);
    }

    public function test_unanswered_and_busy_calls_fail(): void
    {
        Queue::fake();

        $noAnswer = $this->queuedDelivery(ChannelType::Voice, 'CA_NOANSWER');
        $busy = $this->queuedDelivery(ChannelType::Voice, 'CA_BUSY');
        $hungUp = $this->queuedDelivery(ChannelType::Voice, 'CA_ZERO');

        $this->postStatus(['CallSid' => 'CA_NOANSWER', 'CallStatus' => 'no-answer', 'CallDuration' => '0']);
        $this->postStatus(['CallSid' => 'CA_BUSY', 'CallStatus' => 'busy']);
        $this->postStatus(['CallSid' => 'CA_ZERO', 'CallStatus' => 'completed', 'CallDuration' => '0']);

        $this->assertSame(DeliveryStatus::Failed, $noAnswer->fresh()->status);
        $this->assertSame('no-answer', $noAnswer->fresh()->provider_status);
        $this->assertSame(DeliveryStatus::Failed, $busy->fresh()->status);
        $this->assertSame(DeliveryStatus::Failed, $hungUp->fresh()->status);
    }

    public function test_events_for_an_older_attempt_do_not_touch_the_current_one(): void
    {
        $delivery = $this->queuedDelivery(ChannelType::Sms, 'SM_NEW_ATTEMPT');
        MessagingCharge::factory()->forDelivery($delivery)->create(['provider_sid' => 'SM_OLD_ATTEMPT']);

        $this->postStatus(['MessageSid' => 'SM_OLD_ATTEMPT', 'MessageStatus' => 'undelivered', 'ErrorCode' => '30003']);

        $this->assertSame(DeliveryStatus::Queued, $delivery->fresh()->status);
        $this->assertSame('undelivered', MessagingCharge::withoutGlobalScopes()->where('provider_sid', 'SM_OLD_ATTEMPT')->value('status'));

        $entries = array_values(array_filter(
            $this->systemLogEntries('notifications.provider_status.skipped'),
            fn (array $e) => $e['context']['reason'] === 'superseded_attempt',
        ));
        $this->assertCount(1, $entries);
        $this->assertSame('info', $entries[0]['level']);
        $this->assertSame($delivery->id, $entries[0]['context']['input']['delivery_id']);
        $this->assertSystemNotLogged('notifications.provider_status.applied');
    }

    public function test_callback_only_touches_the_tenant_that_owns_the_sid(): void
    {
        $victim = Team::factory()->create();
        $victimDelivery = $this->queuedDelivery(ChannelType::Sms, 'SM_VICTIM', $victim);
        $this->queuedDelivery(ChannelType::Sms, 'SM_OWNER');

        $this->assertNoTenantLeak($this->team, function () {
            $this->postStatus(['MessageSid' => 'SM_OWNER', 'MessageStatus' => 'delivered'])->assertOk();
        });

        $this->assertSame(DeliveryStatus::Queued, $victimDelivery->fresh()->status);
    }

    public function test_signature_is_validated_against_the_configured_public_url(): void
    {
        $this->queuedDelivery(ChannelType::Sms, 'SM_PROXY');
        config()->set('services.twilio.status_callback_url', 'https://hooks.example.com/api/webhooks/twilio/status');

        $params = ['MessageSid' => 'SM_PROXY', 'MessageStatus' => 'delivered'];
        $validator = new RequestValidator(self::AUTH_TOKEN);

        $this->post(self::PATH, $params, [
            'X-Twilio-Signature' => $validator->computeSignature('https://hooks.example.com/api/webhooks/twilio/status', $params),
        ])->assertOk();

        $this->post(self::PATH, $params, [
            'X-Twilio-Signature' => $validator->computeSignature(url(self::PATH), $params),
        ])->assertForbidden();
    }

    /**
     * @param  array<string, string>  $params
     */
    private function postStatus(array $params): TestResponse
    {
        $signature = (new RequestValidator(self::AUTH_TOKEN))->computeSignature(url(self::PATH), $params);

        return $this->post(self::PATH, $params, ['X-Twilio-Signature' => $signature]);
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
            'sent_at' => now(),
            'payload_json' => ['address' => '+5215512345678', 'subject' => null, 'body' => 'Aviso'],
        ]);

        MessagingCharge::factory()->forDelivery($delivery)->create();

        return $delivery;
    }
}
