<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Actions\ApplyTwilioStatusUpdate;
use App\Domains\Notifications\Actions\FinalizeMessagingCharge;
use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Channels\TwilioMessenger;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use App\Domains\Notifications\Jobs\FallbackNotificationChannelJob;
use App\Domains\Notifications\Jobs\RetryNotificationDeliveryJob;
use App\Domains\Notifications\Models\MessagingAddressSuppression;
use App\Domains\Notifications\Models\MessagingCharge;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\Notifications\Support\MessagingSuppressions;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\NotificationMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;
use Twilio\Exceptions\RestException;
use Twilio\Security\RequestValidator;

/**
 * STOP, fijo o sin WhatsApp: el siguiente aviso ya no intenta (ni cobra) ese
 * canal para esa dirección; START/ALTA lo vuelve a abrir.
 */
class MessagingSuppressionTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    private const PHONE = '+5215512345678';

    private const TWILIO_NUMBER = '+14155238886';

    private Team $team;

    private \stdClass $twilio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(NotificationMeterSeeder::class);
        $this->team = User::factory()->create()->currentTeam;

        config()->set('services.twilio.account_sid', 'AC123');
        config()->set('services.twilio.auth_token', 'tok-456');

        NotificationChannel::factory()->sms()->create(['config_json' => ['from' => self::TWILIO_NUMBER]]);
        NotificationChannel::factory()->web()->create();

        $this->twilio = new \stdClass;
        $this->twilio->creates = 0;
        $this->twilio->error = null;

        $messenger = Mockery::mock(TwilioMessenger::class);
        $messenger->shouldReceive('createMessage')->andReturnUsing(function () {
            $this->twilio->creates++;

            if ($this->twilio->error !== null) {
                throw new RestException('twilio error', $this->twilio->error, 400);
            }

            return (object) ['sid' => 'SM'.str_pad((string) $this->twilio->creates, 32, '0', STR_PAD_LEFT), 'status' => 'queued'];
        });
        $this->app->instance(TwilioMessenger::class, $messenger);
    }

    private function send(string $key): Notification
    {
        return app(SendNotification::class)->execute(
            teamId: $this->team->id,
            notificationType: 'incident.created',
            sourceType: NotificationSourceType::SystemEvent,
            sourceReferenceId: '1',
            priority: NotificationPriority::Critical,
            triggeredByType: NotificationTriggeredByType::System,
            triggeredById: null,
            eventKey: $key,
            payload: ['force_channels' => ['sms', 'web'], 'recipients' => [['address' => self::PHONE]]],
        );
    }

    public function test_a_stop_error_suppresses_sms_for_the_next_notice(): void
    {
        Queue::fake([RetryNotificationDeliveryJob::class, FallbackNotificationChannelJob::class]);
        $this->twilio->error = 21610;

        $this->send('first');
        $this->assertSame(1, $this->twilio->creates);

        $suppression = MessagingAddressSuppression::query()->sole();
        $this->assertSame(ChannelType::Sms, $suppression->channel_type);
        $this->assertSame(self::PHONE, $suppression->address);
        $this->assertSame('opted_out', $suppression->reason);

        $second = $this->send('second');

        $this->assertSame(1, $this->twilio->creates, 'no se vuelve a intentar (ni cobrar) el SMS');
        $skipped = NotificationDelivery::withoutGlobalScopes()->where('notification_id', $second->id)->where('status', DeliveryStatus::Skipped)->sole();
        // Hacia el tenant, sin motivo: no se revela que alguien dio STOP.
        $this->assertSame('address unavailable for sms', $skipped->error_message);
        $this->assertSame(1, NotificationDelivery::withoutGlobalScopes()->where('notification_id', $second->id)->where('status', DeliveryStatus::Delivered)->count());

        $this->assertSystemLogged('notifications.suppression.recorded', fn (array $c) => $c['outcome'] === 'ok'
            && $c['calc'] === ['reason' => 'opted_out', 'provider_error_code' => '21610']);
        $this->assertSystemLogged('notifications.delivery.skipped', fn (array $c) => $c['reason'] === 'suppressed'
            && $c['calc'] === ['suppression_reason' => 'opted_out']);
        $this->assertStringNotContainsString('5512345678', json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_addresses_normalize_to_plus_and_digits(): void
    {
        $this->assertSame('+525512345678', MessagingSuppressions::normalize('whatsapp:+52 (55) 1234-5678'));
    }

    public function test_a_transient_error_never_suppresses(): void
    {
        Queue::fake([RetryNotificationDeliveryJob::class, FallbackNotificationChannelJob::class]);
        $this->twilio->error = 30003;

        $this->send('first');

        $this->assertSame(0, MessagingAddressSuppression::query()->count());
    }

    public function test_a_no_whatsapp_callback_suppresses_whatsapp_only(): void
    {
        Queue::fake([RetryNotificationDeliveryJob::class, FallbackNotificationChannelJob::class]);
        $whatsapp = NotificationChannel::factory()->whatsapp()->create();
        $notification = Notification::factory()->create(['team_id' => $this->team->id]);
        $recipient = NotificationRecipient::factory()->create(['notification_id' => $notification->id, 'team_id' => $this->team->id]);
        $delivery = NotificationDelivery::factory()->create([
            'notification_id' => $notification->id,
            'recipient_id' => $recipient->id,
            'channel_id' => $whatsapp->id,
            'team_id' => $this->team->id,
            'status' => DeliveryStatus::Queued,
            'provider_message_id' => 'SM'.str_repeat('a', 32),
            'payload_json' => ['address' => self::PHONE, 'subject' => null, 'body' => 'x'],
        ]);
        $charge = MessagingCharge::factory()->forDelivery($delivery)->create();

        app(ApplyTwilioStatusUpdate::class)->execute($charge, 'failed', '63003');

        $this->assertSame([ChannelType::Whatsapp], MessagingAddressSuppression::query()->get()->pluck('channel_type')->all());
    }

    private function inbound(string $body, string $from = self::PHONE, string $to = self::TWILIO_NUMBER): string
    {
        $params = ['From' => $from, 'To' => $to, 'Body' => $body];
        $signature = (new RequestValidator('tok-456'))->computeSignature(url('/api/webhooks/twilio'), $params);

        // Cuerpo form-encoded crudo, como lo manda Twilio: TrimStrings recorta
        // los parámetros, pero la firma se valida contra el cuerpo original.
        return (string) $this->call('POST', '/api/webhooks/twilio', $params, [], [], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_X_TWILIO_SIGNATURE' => $signature,
        ], http_build_query($params))->assertOk()->getContent();
    }

    public function test_baja_and_alta_replies_toggle_the_suppression(): void
    {
        $reply = $this->inbound('baja');

        $this->assertStringContainsString('Responde ALTA', $reply);
        $this->assertTrue(MessagingAddressSuppression::query()->where('address', self::PHONE)->where('channel_type', 'sms')->exists());

        $reply = $this->inbound('ALTA');

        $this->assertStringContainsString('volverás a recibir', $reply);
        $this->assertFalse(MessagingAddressSuppression::query()->exists());
        $this->assertSystemLogged('notifications.suppression.lifted', fn (array $c) => $c['result'] === ['lifted' => true]);
    }

    public function test_standard_stop_keyword_suppresses_without_a_second_reply(): void
    {
        // Twilio ya contesta solo el STOP estándar: SAM no manda otro mensaje.
        NotificationChannel::factory()->whatsapp()->create(['config_json' => ['from' => 'whatsapp:'.self::TWILIO_NUMBER]]);

        $reply = $this->inbound(' Stop ', 'whatsapp:'.self::PHONE, 'whatsapp:'.self::TWILIO_NUMBER);

        $this->assertStringNotContainsString('<Message>', $reply);
        $this->assertTrue(MessagingAddressSuppression::query()->where('address', self::PHONE)->where('channel_type', 'whatsapp')->exists());
    }

    public function test_a_non_usd_price_is_estimated_instead_of_billed_as_usd(): void
    {
        $notification = Notification::factory()->create(['team_id' => $this->team->id]);
        $recipient = NotificationRecipient::factory()->create(['notification_id' => $notification->id, 'team_id' => $this->team->id]);
        $delivery = NotificationDelivery::factory()->create([
            'notification_id' => $notification->id,
            'recipient_id' => $recipient->id,
            'channel_id' => NotificationChannel::query()->where('channel_type', 'sms')->value('id'),
            'team_id' => $this->team->id,
            'status' => DeliveryStatus::Delivered,
            'provider_message_id' => 'SM'.str_repeat('m', 32),
        ]);
        $charge = MessagingCharge::factory()->forDelivery($delivery)->create(['segments' => 1]);

        app(FinalizeMessagingCharge::class)->withProviderPrice($charge, '-1.50000', 'MXN');

        $fresh = $charge->fresh();
        $this->assertTrue((bool) $fresh->price_estimated);
        $this->assertNotSame(1_500_000, $fresh->price_micros);
        $this->assertSystemLogged('billing.messaging_charge.non_usd_price', fn (array $c) => $c['reason'] === 'currency_mismatch'
            && $c['calc'] === ['price_unit' => 'MXN']);
    }

    public function test_a_stop_from_one_tenants_notice_applies_to_every_tenant_without_revealing_it(): void
    {
        Queue::fake([RetryNotificationDeliveryJob::class, FallbackNotificationChannelJob::class]);
        $this->twilio->error = 21610;
        $this->send('tenant_a');

        $otherTeam = User::factory()->create()->currentTeam;
        $this->twilio->error = null;
        $creates = $this->twilio->creates;

        // Mismo número, desde otro tenant y crítico.
        $notice = app(SendNotification::class)->execute(
            teamId: $otherTeam->id,
            notificationType: 'incident.created',
            sourceType: NotificationSourceType::SystemEvent,
            sourceReferenceId: '1',
            priority: NotificationPriority::Critical,
            triggeredByType: NotificationTriggeredByType::System,
            triggeredById: null,
            eventKey: 'tenant_b',
            payload: ['force_channels' => ['sms', 'web'], 'recipients' => [['address' => self::PHONE]]],
        );

        $this->assertSame($creates, $this->twilio->creates, 'Twilio rechazaría el envío (21610): no se paga el intento');
        $skipped = NotificationDelivery::withoutGlobalScopes()->where('notification_id', $notice->id)->where('status', DeliveryStatus::Skipped)->sole();
        $this->assertSame($otherTeam->id, $skipped->team_id);
        $this->assertSame('address unavailable for sms', $skipped->error_message);
        $this->assertSame(1, NotificationDelivery::withoutGlobalScopes()->where('notification_id', $notice->id)->where('status', DeliveryStatus::Delivered)->count(), 'la app sigue avisando');

        // La fila de supresión no lleva rastro del tenant que la originó.
        $this->assertArrayNotHasKey('team_id', MessagingAddressSuppression::query()->sole()->getAttributes());
    }
}
