<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Drivers\Models\Driver;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Exceptions\ProviderUnauthorized;
use App\Domains\Integrations\Exceptions\ProviderUnavailable;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Notifications\Actions\DispatchNotification;
use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use App\Domains\Notifications\Enums\RecipientType;
use App\Domains\Notifications\Events\NotificationFailed;
use App\Domains\Notifications\Models\MessagingCharge;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\Notifications\Support\ProvidedChannels;
use App\Domains\Notifications\Support\SamsaraDriverAppAddress;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\Feature\Domains\Drivers\Hos\HosTenantFixtures;
use Tests\TestCase;

/**
 * Canal gratis a la app del chofer en Samsara (`POST /v1/fleet/messages`):
 * sin medidor, sin cargo Twilio y sólo para destinatarios `driver`.
 */
class SamsaraDriverAppChannelTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, HosTenantFixtures, RefreshDatabase;

    public function test_the_adapter_posts_the_message_to_the_driver_app(): void
    {
        $this->fakeAppMessages();
        $integration = $this->hosIntegration(feature: false);

        app(ProviderAdapter::class)->sendDriverMessage($integration, '58072405', 'Hola, soy SAM');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://api.samsara.com/v1/fleet/messages'
            && $request['driverIds'] === [58072405]
            && $request['text'] === 'Hola, soy SAM'
            && $request->hasHeader('Authorization', 'Bearer sk-test'));
    }

    public function test_the_adapter_types_its_failures(): void
    {
        $integration = $this->hosIntegration(feature: false);
        Http::fake(['api.samsara.com/v1/fleet/messages' => Http::sequence()
            ->push(['message' => 'missing scope'], 403)
            ->push([], 503)]);

        try {
            app(ProviderAdapter::class)->sendDriverMessage($integration, '58072405', 'Hola');
            $this->fail('Un 403 debe ser ProviderUnauthorized.');
        } catch (ProviderUnauthorized) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(ProviderUnavailable::class);
        app(ProviderAdapter::class)->sendDriverMessage($integration, '58072405', 'Hola');
    }

    public function test_a_driver_recipient_gets_the_message_in_the_samsara_app_without_metering(): void
    {
        $this->fakeAppMessages();
        NotificationChannel::factory()->samsaraDriverApp()->create();
        $integration = $this->hosIntegration(feature: false);
        [$driver] = $this->hosDriver($integration);

        $notification = $this->notifyDriver($integration, $driver, SamsaraDriverAppAddress::make($integration->id, '58072405'));

        $delivery = NotificationDelivery::withoutGlobalScopes()->sole();
        $this->assertSame(DeliveryStatus::Delivered, $delivery->status);
        $this->assertSame(NotificationStatus::Sent, $notification->fresh()->status);
        Http::assertSentCount(1);
        $this->assertSame(0, MessagingCharge::withoutGlobalScopes()->count());

        $sent = $this->assertSystemLogged('notifications.delivery.sent');
        $this->assertSame('samsara_driver_app', $sent['input']['channel_type']);
        $this->assertNull($sent['result']['usage_meter_code']);
        $this->assertFalse($sent['result']['usage_metered']);
        $this->assertNoSensitiveDataLogged();
        $this->assertStringNotContainsString('Secreto', json_encode($this->systemLogEntries()));
    }

    public function test_an_address_pointing_at_another_tenants_integration_is_never_used(): void
    {
        Http::fake();
        Event::fake([NotificationFailed::class]);
        NotificationChannel::factory()->samsaraDriverApp()->create();
        $mine = $this->hosIntegration(feature: false);
        $foreign = $this->hosIntegration(feature: false);
        [$driver] = $this->hosDriver($mine);

        $this->assertNoTenantLeak($mine->team_id, fn () => $this->notifyDriver($mine, $driver, SamsaraDriverAppAddress::make($foreign->id, '58072405')));

        $delivery = NotificationDelivery::withoutGlobalScopes()->sole();
        $this->assertSame(DeliveryStatus::Failed, $delivery->status);
        $this->assertTrue((bool) $delivery->permanent_failure);
        Http::assertNothingSent();
        $this->assertSame('integration_missing', $this->assertSystemLogged('notifications.delivery.failed')['result']['provider_error_code']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_only_drivers_have_a_samsara_app_address(): void
    {
        $metadata = [NotificationRecipient::SAMSARA_APP_ADDRESS_KEY => 'samsara:1:2'];

        $user = new NotificationRecipient(['recipient_type' => RecipientType::User, 'address' => 'ops@example.com', 'metadata_json' => $metadata]);
        $this->assertNull($user->addressForChannel(ChannelType::SamsaraDriverApp));

        $driver = new NotificationRecipient(['recipient_type' => RecipientType::Driver, 'address' => 'driver:9', 'metadata_json' => $metadata]);
        $this->assertSame('samsara:1:2', $driver->addressForChannel(ChannelType::SamsaraDriverApp));

        $withoutApp = new NotificationRecipient(['recipient_type' => RecipientType::Driver, 'address' => 'driver:9']);
        $this->assertNull($withoutApp->addressForChannel(ChannelType::SamsaraDriverApp));
    }

    public function test_the_address_round_trips_and_rejects_anything_else(): void
    {
        $this->assertSame(
            ['integration_id' => 7, 'external_driver_id' => '58072405'],
            SamsaraDriverAppAddress::parse(SamsaraDriverAppAddress::make(7, '58072405')),
        );
        $this->assertNull(SamsaraDriverAppAddress::parse('ops@example.com'));
        $this->assertNull(SamsaraDriverAppAddress::parse('samsara:7:abc'));
    }

    public function test_the_tenant_ui_never_offers_the_driver_only_channel(): void
    {
        NotificationChannel::factory()->samsaraDriverApp()->create();
        NotificationChannel::factory()->sms()->create();

        $this->assertSame(['sms'], array_map(fn (ChannelType $type) => $type->value, app(ProvidedChannels::class)->types()));
        $this->assertSame('App de Samsara', ChannelType::SamsaraDriverApp->label());
    }

    private function notifyDriver(TenantIntegration $integration, Driver $driver, string $appAddress): Notification
    {
        return TenantContext::for($integration->team_id, function () use ($integration, $driver, $appAddress): Notification {
            $notification = app(SendNotification::class)->execute(
                teamId: $integration->team_id,
                notificationType: 'manual.driver_message',
                sourceType: NotificationSourceType::Manual,
                sourceReferenceId: null,
                priority: NotificationPriority::High,
                triggeredByType: NotificationTriggeredByType::System,
                triggeredById: null,
                eventKey: 'test:driver-app:'.$driver->id,
                payload: [
                    'force_channels' => ['samsara_driver_app'],
                    'recipients' => [[
                        'recipient_type' => 'driver',
                        'address' => 'driver:'.$driver->id,
                        'name' => $driver->full_name,
                        'recipient_reference_id' => (string) $driver->id,
                        'metadata' => [NotificationRecipient::SAMSARA_APP_ADDRESS_KEY => $appAddress],
                    ]],
                ],
                subject: 'Prueba',
                bodyPreview: 'SAM: mensaje de prueba para el chofer.',
                dispatchJob: false,
            );

            return app(DispatchNotification::class)->execute($notification);
        });
    }
}
