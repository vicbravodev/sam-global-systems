<?php

namespace App\Domains\Notifications\Channels;

use App\Contracts\Notifications\NotificationDriver;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Exceptions\ProviderRequestFailed;
use App\Domains\Integrations\Exceptions\ProviderRequestFailedException;
use App\Domains\Integrations\Exceptions\ProviderUnauthorized;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Support\SamsaraDriverAppAddress;
use App\Support\SafeErrorMessage;

/**
 * Mensaje a la app del chofer en Samsara (monitoreo HOS, spec 2026-10-04
 * §3.10). Gratis: no hay recurso Twilio ni medidor. Síncrono: si Samsara lo
 * acepta, la entrega queda `delivered`.
 *
 * La integración sale de la dirección, pero sólo se usa si es del team de
 * la entrega (la dirección es un id de proveedor: nunca se confía en ella
 * sola). Un 401/403 (token sin "Write Messages") es permanente.
 *
 * Corre dentro del TenantContext de la entrega (AttemptDelivery).
 */
class SamsaraDriverAppNotificationDriver implements NotificationDriver
{
    public function __construct(
        private readonly ProviderAdapter $providers,
    ) {}

    public function send(RenderedNotification $notification, NotificationChannel $channel): DeliveryResult
    {
        $target = SamsaraDriverAppAddress::parse($notification->address);

        if ($target === null) {
            return DeliveryResult::failure('samsara driver app address is invalid', permanent: true, providerErrorCode: 'invalid_address');
        }

        $teamId = $notification->deliveryId !== null
            ? NotificationDelivery::query()->whereKey($notification->deliveryId)->value('team_id')
            : null;

        $integration = is_numeric($teamId)
            ? TenantIntegration::query()
                ->where('team_id', (int) $teamId)
                ->where('status', TenantIntegrationStatus::Active)
                ->with('provider')
                ->find($target['integration_id'])
            : null;

        if ($integration === null || $integration->provider?->code !== 'samsara') {
            return DeliveryResult::failure('samsara integration not found for this team', permanent: true, providerErrorCode: 'integration_missing');
        }

        try {
            $this->providers->sendDriverMessage($integration, $target['external_driver_id'], $notification->body);
        } catch (ProviderUnauthorized $e) {
            return DeliveryResult::failure(SafeErrorMessage::from($e), permanent: true, providerErrorCode: 'unauthorized');
        } catch (ProviderRequestFailed|ProviderRequestFailedException $e) {
            return DeliveryResult::failure(SafeErrorMessage::from($e), providerErrorCode: 'provider_error');
        }

        return DeliveryResult::success();
    }
}
