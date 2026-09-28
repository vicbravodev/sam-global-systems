<?php

namespace App\Domains\Integrations\Jobs;

use App\Domains\Assets\Models\Asset;
use App\Domains\Incidents\Support\IncidentSupervisors;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use App\Support\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Salud de la integración (decisión 2026-09-28): si el proveedor deja de
 * responder, la flota deja de vigilarse y la pantalla se queda "en calma".
 * El admin de la empresa se entera, por app, correo y SMS a su teléfono
 * verificado, en cuanto:
 *
 * - la integración queda en `error` (token revocado, 401), o
 * - la integración está callada: ni feed de posiciones, ni webhooks, ni
 *   eventos en `telematics.integration_silence_minutes` teniendo unidades
 *   vigiladas.
 *
 * Sólo vigila integraciones que ya sincronizaron sus entidades principales
 * (`last_sync_at`): antes de eso no hay flota que proteger. Un aviso por
 * episodio (clave = marca de tiempo del error o del último dato).
 */
class CheckIntegrationHealthJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue('sync');
    }

    public function handle(SendNotification $sendNotification): void
    {
        // Vigilancia de plataforma: recorre todos los tenants a propósito y
        // evalúa cada integración dentro del contexto del suyo (§2.1).
        TenantContext::withoutTenant(fn () => TenantIntegration::query()
            ->whereNotNull('team_id')
            ->whereNotNull('last_sync_at')
            ->whereIn('status', [TenantIntegrationStatus::Active, TenantIntegrationStatus::Error])
            ->chunkById(100, function ($integrations) use ($sendNotification) {
                foreach ($integrations as $integration) {
                    TenantContext::for((int) $integration->team_id, fn () => $this->inspect($integration, $sendNotification));
                }
            }));
    }

    private function inspect(TenantIntegration $integration, SendNotification $sendNotification): void
    {
        $teamId = (int) $integration->team_id;

        $hasMonitoredFleet = Asset::query()->where('team_id', $teamId)->monitored()->exists();

        if (! $hasMonitoredFleet) {
            return;
        }

        if ($integration->status === TenantIntegrationStatus::Error) {
            $since = $integration->last_error_at ?? $integration->updated_at ?? now();

            $this->alert(
                $sendNotification,
                $integration,
                eventKey: sprintf('integration_error:%d:%d', $integration->id, $since->getTimestamp()),
                subject: "La integración {$integration->name} dejó de responder",
                body: 'SAM no puede leer tu flota desde el proveedor'
                    .($integration->last_error_message ? " ({$integration->last_error_message})" : '')
                    .'. Mientras tanto no llegan pánicos ni posiciones: revisa las credenciales en Integraciones.',
            );

            return;
        }

        $lastData = $this->lastDataAt($integration);
        $silenceMinutes = max(5, (int) config('telematics.integration_silence_minutes', 30));

        if ($lastData !== null && $lastData->gt(now()->subMinutes($silenceMinutes))) {
            return;
        }

        $anchor = $lastData ?? $integration->last_sync_at;

        $this->alert(
            $sendNotification,
            $integration,
            eventKey: sprintf('integration_silent:%d:%d', $integration->id, $anchor->getTimestamp()),
            subject: "Sin datos de {$integration->name} desde hace más de {$silenceMinutes} min",
            body: 'Tu flota vigilada no está enviando posiciones ni eventos a SAM'
                .($lastData ? ' desde el '.$lastData->copy()->setTimezone((string) config('billing.timezone', 'America/Mexico_City'))->format('d/m H:i') : '')
                .'. Puede ser el proveedor, el token o los webhooks: revisa Integraciones.',
        );
    }

    private function lastDataAt(TenantIntegration $integration): ?CarbonInterface
    {
        $webhook = WebhookEndpoint::query()
            ->where('tenant_integration_id', $integration->id)
            ->max('last_received_at');

        // Sólo lo que manda el proveedor: los eventos internos (p. ej. el
        // propio "dejó de reportar") no prueban que la integración viva.
        $rawEvent = RawEvent::query()
            ->where('team_id', $integration->team_id)
            ->where('provider_id', $integration->provider_id)
            ->max('received_at');

        $candidates = array_filter([
            $integration->last_location_poll_at,
            $integration->last_telemetry_poll_at,
            $webhook !== null ? Carbon::parse($webhook) : null,
            $rawEvent !== null ? Carbon::parse($rawEvent) : null,
        ]);

        if ($candidates === []) {
            return null;
        }

        return collect($candidates)->sortDesc()->first();
    }

    private function alert(SendNotification $sendNotification, TenantIntegration $integration, string $eventKey, string $subject, string $body): void
    {
        $recipients = IncidentSupervisors::recipients((int) $integration->team_id);

        if ($recipients === []) {
            return;
        }

        $sendNotification->execute(
            teamId: (int) $integration->team_id,
            notificationType: 'integration.health',
            sourceType: NotificationSourceType::SystemEvent,
            sourceReferenceId: (string) $integration->id,
            priority: NotificationPriority::High,
            triggeredByType: NotificationTriggeredByType::System,
            triggeredById: null,
            eventKey: $eventKey,
            payload: [
                'integration_id' => $integration->id,
                'recipients' => $recipients,
                'force_channels' => [ChannelType::Web->value, ChannelType::Email->value, ChannelType::Sms->value],
            ],
            subject: $subject,
            bodyPreview: $body,
        );
    }
}
