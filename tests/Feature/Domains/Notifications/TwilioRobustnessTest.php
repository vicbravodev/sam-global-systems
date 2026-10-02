<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Actions\ApplyTwilioStatusUpdate;
use App\Domains\Notifications\Actions\RecordDeliveryAttempt;
use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Channels\TwilioMessenger;
use App\Domains\Notifications\Channels\TwilioVoiceCaller;
use App\Domains\Notifications\Channels\VoiceNotificationDriver;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use App\Domains\Notifications\Jobs\FallbackNotificationChannelJob;
use App\Domains\Notifications\Jobs\ResolveUncertainDeliveryJob;
use App\Domains\Notifications\Jobs\RetryNotificationDeliveryJob;
use App\Domains\Notifications\Jobs\SweepStuckDeliveriesJob;
use App\Domains\Notifications\Models\MessagingCharge;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\Notifications\Support\TwilioErrorCatalog;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\NotificationMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;
use Twilio\Exceptions\EnvironmentException;
use Twilio\Exceptions\RestException;
use Twilio\Security\RequestValidator;

/**
 * Cada SMS/llamada se cobra: un timeout, dos jobs a la vez o un worker caído
 * nunca deben producir un envío doble ni dejar un aviso perdido en silencio,
 * y un buzón de voz no cuenta como "contestó".
 */
class TwilioRobustnessTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    private const PHONE = '+5215512345678';

    private const FROM = '+14155238886';

    private Team $team;

    private NotificationChannel $sms;

    private \stdClass $twilio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(NotificationMeterSeeder::class);
        $this->team = User::factory()->create()->currentTeam;

        config()->set('services.twilio.account_sid', 'AC123');
        config()->set('services.twilio.auth_token', 'tok-456');

        $this->sms = NotificationChannel::factory()->sms()->create(['config_json' => ['from' => self::FROM]]);

        $this->twilio = new \stdClass;
        $this->twilio->creates = 0;
        $this->twilio->createBehavior = 'accept';
        $this->twilio->found = [];

        $messenger = Mockery::mock(TwilioMessenger::class);
        $messenger->shouldReceive('createMessage')->andReturnUsing(function () {
            $this->twilio->creates++;

            return match ($this->twilio->createBehavior) {
                'timeout' => throw new EnvironmentException('Operation timed out after 60000 milliseconds'),
                'rest_error' => throw new RestException('invalid number', 21211, 400),
                default => (object) ['sid' => 'SM'.str_pad((string) $this->twilio->creates, 32, '0', STR_PAD_LEFT), 'status' => 'queued'],
            };
        });
        $messenger->shouldReceive('findRecentMessages')->andReturnUsing(fn () => $this->twilio->found);
        $this->app->instance(TwilioMessenger::class, $messenger);
    }

    private function sendSms(): Notification
    {
        return app(SendNotification::class)->execute(
            teamId: $this->team->id,
            notificationType: 'incident.created',
            sourceType: NotificationSourceType::SystemEvent,
            sourceReferenceId: '1',
            priority: NotificationPriority::Critical,
            triggeredByType: NotificationTriggeredByType::System,
            triggeredById: null,
            eventKey: 'robustness:'.uniqid(),
            payload: ['force_channels' => ['sms'], 'recipients' => [['address' => self::PHONE]]],
            subject: 'Pánico',
            bodyPreview: 'Unidad 42',
        );
    }

    private function smsDelivery(Notification $notification): NotificationDelivery
    {
        return NotificationDelivery::withoutGlobalScopes()->where('notification_id', $notification->id)->sole();
    }

    private function resolve(NotificationDelivery $delivery): void
    {
        app()->call([new ResolveUncertainDeliveryJob($delivery->id), 'handle']);
    }

    public function test_a_timeout_never_resends_blindly_and_is_resolved_against_twilio(): void
    {
        Queue::fake([ResolveUncertainDeliveryJob::class, RetryNotificationDeliveryJob::class, FallbackNotificationChannelJob::class]);
        $this->twilio->createBehavior = 'timeout';

        $delivery = $this->smsDelivery($this->sendSms());

        // Ni fallida ni reintentada: en vuelo, a la espera de averiguarlo.
        $this->assertSame(DeliveryStatus::Sending, $delivery->status);
        $this->assertSame(RecordDeliveryAttempt::UNCERTAIN, $delivery->provider_status);
        Queue::assertNotPushed(RetryNotificationDeliveryJob::class);
        Queue::assertPushed(ResolveUncertainDeliveryJob::class, fn (ResolveUncertainDeliveryJob $job) => $job->deliveryId === $delivery->id);
        $this->assertSystemLogged('notifications.delivery.uncertain', fn (array $c) => $c['reason'] === 'provider_outcome_unknown'
            && $c['result']['resolve_job_requested'] === true);

        // Twilio sí lo había creado: se adopta su SID, sin un segundo envío.
        $this->twilio->found = [(object) ['sid' => 'SM'.str_repeat('f', 32), 'status' => 'sent', 'dateCreated' => now(), 'body' => $delivery->payload_json['body']]];
        $this->resolve($delivery);

        $fresh = $delivery->fresh();
        $this->assertSame(DeliveryStatus::Queued, $fresh->status);
        $this->assertSame('SM'.str_repeat('f', 32), $fresh->provider_message_id);
        $this->assertSame(1, $this->twilio->creates, 'un solo SMS creado en Twilio');
        $this->assertSame(1, MessagingCharge::withoutGlobalScopes()->where('provider_sid', 'SM'.str_repeat('f', 32))->count());
        $this->assertSame(1, UsageEvent::withoutGlobalScopes()->where('event_key', "notif_delivery_{$delivery->id}")->count());
        $this->assertSystemLogged('notifications.delivery.uncertain_resolved', fn (array $c) => $c['result'] === ['outcome' => 'adopted']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_an_uncertain_send_twilio_never_got_falls_back_to_the_normal_retry(): void
    {
        Queue::fake([ResolveUncertainDeliveryJob::class, RetryNotificationDeliveryJob::class, FallbackNotificationChannelJob::class]);
        $this->twilio->createBehavior = 'timeout';

        $delivery = $this->smsDelivery($this->sendSms());
        $this->resolve($delivery);

        $fresh = $delivery->fresh();
        $this->assertSame(DeliveryStatus::Failed, $fresh->status);
        $this->assertFalse($fresh->permanent_failure);
        Queue::assertPushed(RetryNotificationDeliveryJob::class, fn (RetryNotificationDeliveryJob $job) => $job->deliveryId === $delivery->id);
        $this->assertSystemLogged('notifications.delivery.uncertain_resolved', fn (array $c) => $c['result'] === ['outcome' => 'failed_for_retry']);
    }

    public function test_a_message_that_belongs_to_another_send_is_never_adopted(): void
    {
        Queue::fake([ResolveUncertainDeliveryJob::class, RetryNotificationDeliveryJob::class, FallbackNotificationChannelJob::class]);
        $this->twilio->createBehavior = 'timeout';
        $delivery = $this->smsDelivery($this->sendSms());

        // El número de plataforma es compartido: al mismo destino hay un SMS
        // de otro tenant (SID ya registrado) y otro con un texto distinto.
        $otherTeam = User::factory()->create()->currentTeam;
        MessagingCharge::factory()->create(['team_id' => $otherTeam->id, 'provider_sid' => 'SM'.str_repeat('1', 32)]);
        $this->twilio->found = [
            (object) ['sid' => 'SM'.str_repeat('1', 32), 'status' => 'sent', 'dateCreated' => now(), 'body' => $delivery->payload_json['body']],
            (object) ['sid' => 'SM'.str_repeat('2', 32), 'status' => 'sent', 'dateCreated' => now(), 'body' => 'Otro aviso'],
        ];

        $this->resolve($delivery);

        $fresh = $delivery->fresh();
        $this->assertNull($fresh->provider_message_id);
        $this->assertSame(DeliveryStatus::Failed, $fresh->status);
        $this->assertSame($otherTeam->id, MessagingCharge::withoutGlobalScopes()->where('provider_sid', 'SM'.str_repeat('1', 32))->sole()->team_id);
        $this->assertSystemLogged('notifications.delivery.uncertain_resolved', fn (array $c) => $c['result'] === ['outcome' => 'failed_for_retry']
            && $c['calc']['candidates_count'] === 2);
    }

    public function test_a_later_send_to_the_same_number_or_an_ambiguous_match_is_never_adopted(): void
    {
        Queue::fake([ResolveUncertainDeliveryJob::class, RetryNotificationDeliveryJob::class, FallbackNotificationChannelJob::class]);
        $this->twilio->createBehavior = 'timeout';
        $delivery = $this->smsDelivery($this->sendSms());
        $body = $delivery->payload_json['body'];

        // Fuera de la ventana del intento (un envío posterior) y dos iguales
        // sin dueño dentro de ella: ninguno es inequívocamente éste.
        $this->twilio->found = [
            (object) ['sid' => 'SM'.str_repeat('3', 32), 'status' => 'sent', 'dateCreated' => now()->addMinutes(30), 'body' => $body],
            (object) ['sid' => 'SM'.str_repeat('4', 32), 'status' => 'sent', 'dateCreated' => now(), 'body' => $body],
            (object) ['sid' => 'SM'.str_repeat('5', 32), 'status' => 'sent', 'dateCreated' => now(), 'body' => $body],
        ];

        $this->resolve($delivery);

        $this->assertNull($delivery->fresh()->provider_message_id);
        $this->assertSystemLogged('notifications.delivery.uncertain_resolved', fn (array $c) => $c['result'] === ['outcome' => 'failed_for_retry']
            && $c['calc']['unclaimed_count'] === 2);
    }

    public function test_a_callback_sid_shared_by_two_deliveries_is_not_adopted(): void
    {
        $sid = 'SM'.str_repeat('6', 32);
        $otherTeam = User::factory()->create()->currentTeam;

        foreach ([$this->team, $otherTeam] as $team) {
            $notification = Notification::factory()->create(['team_id' => $team->id]);
            $recipient = NotificationRecipient::factory()->create(['notification_id' => $notification->id, 'team_id' => $team->id]);
            NotificationDelivery::factory()->create([
                'notification_id' => $notification->id,
                'recipient_id' => $recipient->id,
                'channel_id' => $this->sms->id,
                'team_id' => $team->id,
                'status' => DeliveryStatus::Queued,
                'provider_message_id' => $sid,
            ]);
        }

        $params = ['MessageSid' => $sid, 'MessageStatus' => 'delivered'];
        $signature = (new RequestValidator('tok-456'))->computeSignature(url('/api/webhooks/twilio/status'), $params);

        $this->post('/api/webhooks/twilio/status', $params, ['X-Twilio-Signature' => $signature])->assertOk();

        $this->assertSame(0, MessagingCharge::withoutGlobalScopes()->where('provider_sid', $sid)->count());
        $this->assertSame(0, NotificationDelivery::withoutGlobalScopes()->where('status', DeliveryStatus::Delivered)->count());
        $this->assertSystemLogged('notifications.provider_status.charge_adopted', fn (array $c) => ($c['reason'] ?? null) === 'ambiguous_sid');
    }

    public function test_a_twilio_error_response_is_a_definitive_failure(): void
    {
        Queue::fake([ResolveUncertainDeliveryJob::class, RetryNotificationDeliveryJob::class, FallbackNotificationChannelJob::class]);
        $this->twilio->createBehavior = 'rest_error';

        $delivery = $this->smsDelivery($this->sendSms());

        $this->assertSame(DeliveryStatus::Failed, $delivery->status);
        $this->assertTrue($delivery->permanent_failure);
        Queue::assertNotPushed(ResolveUncertainDeliveryJob::class);
        Queue::assertPushed(FallbackNotificationChannelJob::class);
    }

    public function test_two_retries_of_the_same_failure_send_only_once(): void
    {
        Queue::fake([ResolveUncertainDeliveryJob::class, RetryNotificationDeliveryJob::class, FallbackNotificationChannelJob::class]);
        $this->twilio->createBehavior = 'timeout';
        $delivery = $this->smsDelivery($this->sendSms());
        $this->resolve($delivery);
        $this->twilio->createBehavior = 'accept';
        $sendsBefore = $this->twilio->creates;

        // Callback y reconciliador emitieron cada uno su NotificationFailed:
        // dos jobs de reintento para el mismo intento.
        app()->call([new RetryNotificationDeliveryJob($delivery->id), 'handle']);
        app()->call([new RetryNotificationDeliveryJob($delivery->id), 'handle']);

        $this->assertSame($sendsBefore + 1, $this->twilio->creates);
        $this->assertSame(2, $delivery->fresh()->attempt_number);
        $this->assertSystemLogged('notifications.retry.skipped', fn (array $c) => in_array($c['reason'] ?? null, ['race_lost', 'not_failed'], true));
    }

    public function test_the_retry_claim_is_atomic_against_a_concurrent_claim(): void
    {
        Queue::fake([ResolveUncertainDeliveryJob::class, RetryNotificationDeliveryJob::class, FallbackNotificationChannelJob::class]);
        $this->twilio->createBehavior = 'timeout';
        $delivery = $this->smsDelivery($this->sendSms());
        $this->resolve($delivery);
        $this->twilio->createBehavior = 'accept';
        $sendsBefore = $this->twilio->creates;

        // Otro worker reclama el mismo fallo justo después de que este job
        // leyó la fila (ambos la vieron `failed`, intento 1).
        $claimedElsewhere = false;
        NotificationDelivery::retrieved(function (NotificationDelivery $model) use ($delivery, &$claimedElsewhere) {
            if (! $claimedElsewhere && $model->id === $delivery->id) {
                $claimedElsewhere = true;
                NotificationDelivery::withoutGlobalScopes()->whereKey($delivery->id)->update(['status' => DeliveryStatus::Retrying, 'attempt_number' => 2]);
            }
        });

        app()->call([new RetryNotificationDeliveryJob($delivery->id), 'handle']);

        $this->assertTrue($claimedElsewhere);
        $this->assertSame($sendsBefore, $this->twilio->creates, 'el job que pierde el reclamo no envía');
        $this->assertSystemLogged('notifications.retry.skipped', fn (array $c) => ($c['reason'] ?? null) === 'race_lost');
    }

    public function test_voice_retries_once_before_falling_back(): void
    {
        $voice = NotificationChannel::factory()->voice()->create();
        $notification = Notification::factory()->create(['team_id' => $this->team->id]);
        $recipient = NotificationRecipient::factory()->create(['notification_id' => $notification->id, 'team_id' => $this->team->id]);
        $delivery = NotificationDelivery::factory()->failed()->create([
            'notification_id' => $notification->id,
            'recipient_id' => $recipient->id,
            'channel_id' => $voice->id,
            'team_id' => $this->team->id,
        ]);

        $this->assertSame(2, (new RetryNotificationDeliveryJob($delivery->id))->maxAttempts());
        $this->assertSame(5, (new RetryNotificationDeliveryJob($this->smsDelivery($this->sendSms())->id))->maxAttempts());
    }

    public function test_the_sweeper_rescues_deliveries_stuck_without_a_sid(): void
    {
        Queue::fake([ResolveUncertainDeliveryJob::class]);
        $notification = Notification::factory()->create(['team_id' => $this->team->id]);
        $make = function (array $attributes) use ($notification): NotificationDelivery {
            $recipient = NotificationRecipient::factory()->create(['notification_id' => $notification->id, 'team_id' => $this->team->id]);

            return NotificationDelivery::factory()->create([
                'notification_id' => $notification->id,
                'recipient_id' => $recipient->id,
                'channel_id' => $this->sms->id,
                'team_id' => $this->team->id,
                ...$attributes,
            ]);
        };

        $stuck = $make(['status' => DeliveryStatus::Sending, 'provider_message_id' => null, 'attempt_number' => 3]);
        $recent = $make(['status' => DeliveryStatus::Sending, 'provider_message_id' => null]);
        $ancient = $make(['status' => DeliveryStatus::Pending, 'provider_message_id' => null]);
        $accepted = $make(['status' => DeliveryStatus::Sending, 'provider_message_id' => 'SM'.str_repeat('a', 32)]);

        NotificationDelivery::withoutGlobalScopes()->whereKey($stuck->id)->update(['updated_at' => now()->subMinutes(15)]);
        NotificationDelivery::withoutGlobalScopes()->whereKey($ancient->id)->update(['updated_at' => now()->subMinutes(SweepStuckDeliveriesJob::MAX_AGE_MINUTES + 5)]);
        NotificationDelivery::withoutGlobalScopes()->whereKey($accepted->id)->update(['updated_at' => now()->subMinutes(15)]);

        app()->call([new SweepStuckDeliveriesJob, 'handle']);

        Queue::assertPushed(ResolveUncertainDeliveryJob::class, 1);
        Queue::assertPushed(ResolveUncertainDeliveryJob::class, fn (ResolveUncertainDeliveryJob $job) => $job->deliveryId === $stuck->id);
        $this->assertSame("notif_retry_{$stuck->id}_3", ResolveUncertainDeliveryJob::usageEventKey($stuck->fresh()));
        $this->assertSystemLogged('notifications.stuck_sweep.completed', fn (array $c) => $c['result']['delivery_ids'] === [$stuck->id]
            && $c['calc']['stuck_minutes'] === SweepStuckDeliveriesJob::STUCK_MINUTES);
        $this->assertNoSensitiveDataLogged();

        unset($recent);
    }

    public function test_a_voicemail_answer_fails_the_call_instead_of_counting_as_answered(): void
    {
        Queue::fake([RetryNotificationDeliveryJob::class, FallbackNotificationChannelJob::class]);
        $voice = NotificationChannel::factory()->voice()->create();
        $notification = Notification::factory()->create(['team_id' => $this->team->id]);
        $recipient = NotificationRecipient::factory()->create(['notification_id' => $notification->id, 'team_id' => $this->team->id]);
        $delivery = NotificationDelivery::factory()->create([
            'notification_id' => $notification->id,
            'recipient_id' => $recipient->id,
            'channel_id' => $voice->id,
            'team_id' => $this->team->id,
            'status' => DeliveryStatus::Sending,
            'provider_message_id' => 'CA'.str_repeat('b', 32),
        ]);
        $charge = MessagingCharge::factory()->forDelivery($delivery)->create();

        app(ApplyTwilioStatusUpdate::class)->execute($charge, 'in-progress', answeredBy: 'machine_end_beep');

        $fresh = $delivery->fresh();
        $this->assertSame(DeliveryStatus::Failed, $fresh->status);
        $this->assertSame(TwilioErrorCatalog::ANSWERED_BY_MACHINE, $fresh->provider_error_code);
        $this->assertTrue($fresh->permanent_failure, 'un buzón no se reintenta por voz: se pasa a otro canal');
        $this->assertNull($fresh->answered_at);
        Queue::assertPushed(FallbackNotificationChannelJob::class);
    }

    public function test_a_human_answer_still_counts_as_delivered(): void
    {
        $voice = NotificationChannel::factory()->voice()->create();
        $notification = Notification::factory()->create(['team_id' => $this->team->id]);
        $recipient = NotificationRecipient::factory()->create(['notification_id' => $notification->id, 'team_id' => $this->team->id]);
        $delivery = NotificationDelivery::factory()->create([
            'notification_id' => $notification->id,
            'recipient_id' => $recipient->id,
            'channel_id' => $voice->id,
            'team_id' => $this->team->id,
            'status' => DeliveryStatus::Sending,
            'provider_message_id' => 'CA'.str_repeat('c', 32),
        ]);
        $charge = MessagingCharge::factory()->forDelivery($delivery)->create();

        app(ApplyTwilioStatusUpdate::class)->execute($charge, 'in-progress', answeredBy: 'human');

        $this->assertSame(DeliveryStatus::Delivered, $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->answered_at);
    }

    public function test_notification_calls_ask_twilio_to_detect_answering_machines(): void
    {
        $captured = new \stdClass;
        $caller = Mockery::mock(TwilioVoiceCaller::class);
        $caller->shouldReceive('createCall')->andReturnUsing(function (string $to, string $from, array $params) use ($captured) {
            $captured->params = $params;

            return (object) ['sid' => 'CA'.str_repeat('d', 32), 'status' => 'queued'];
        });

        $voice = NotificationChannel::factory()->voice()->create(['config_json' => ['from' => '+15005550006']]);

        (new VoiceNotificationDriver($caller))->send(new RenderedNotification(ChannelType::Voice, self::PHONE, 'Pánico', 'Unidad 42.'), $voice);

        $this->assertSame('Enable', $captured->params['machineDetection']);
    }

    public function test_a_status_callback_that_beats_the_charge_record_is_adopted(): void
    {
        $notification = Notification::factory()->create(['team_id' => $this->team->id]);
        $recipient = NotificationRecipient::factory()->create(['notification_id' => $notification->id, 'team_id' => $this->team->id]);
        $sid = 'SM'.str_repeat('e', 32);
        $delivery = NotificationDelivery::factory()->create([
            'notification_id' => $notification->id,
            'recipient_id' => $recipient->id,
            'channel_id' => $this->sms->id,
            'team_id' => $this->team->id,
            'status' => DeliveryStatus::Queued,
            'provider_message_id' => $sid,
        ]);

        $params = ['MessageSid' => $sid, 'MessageStatus' => 'delivered'];
        $url = url('/api/webhooks/twilio/status');
        $signature = (new RequestValidator('tok-456'))->computeSignature($url, $params);

        $this->post('/api/webhooks/twilio/status', $params, ['X-Twilio-Signature' => $signature])->assertOk();

        $this->assertSame(DeliveryStatus::Delivered, $delivery->fresh()->status);
        $this->assertSame($this->team->id, MessagingCharge::withoutGlobalScopes()->where('provider_sid', $sid)->sole()->team_id);
        $this->assertSystemLogged('notifications.provider_status.charge_adopted', fn (array $c) => $c['input'] === ['delivery_id' => $delivery->id]);
    }

    public function test_webhook_signatures_validate_against_the_public_base_url_behind_a_proxy(): void
    {
        config()->set('services.twilio.public_base_url', 'https://sam.example.com');
        $sid = 'SM'.str_repeat('9', 32);
        $notification = Notification::factory()->create(['team_id' => $this->team->id]);
        $recipient = NotificationRecipient::factory()->create(['notification_id' => $notification->id, 'team_id' => $this->team->id]);
        NotificationDelivery::factory()->create([
            'notification_id' => $notification->id,
            'recipient_id' => $recipient->id,
            'channel_id' => $this->sms->id,
            'team_id' => $this->team->id,
            'status' => DeliveryStatus::Queued,
            'provider_message_id' => $sid,
        ]);

        $params = ['MessageSid' => $sid, 'MessageStatus' => 'sent'];

        // Twilio firmó la URL pública, no la que ve Laravel tras el proxy.
        $publicSignature = (new RequestValidator('tok-456'))->computeSignature('https://sam.example.com/api/webhooks/twilio/status', $params);
        $this->post('/api/webhooks/twilio/status', $params, ['X-Twilio-Signature' => $publicSignature])->assertOk();

        $internalSignature = (new RequestValidator('tok-456'))->computeSignature(url('/api/webhooks/twilio/status'), $params);
        $this->post('/api/webhooks/twilio/status', $params, ['X-Twilio-Signature' => $internalSignature])->assertForbidden();
    }
}
