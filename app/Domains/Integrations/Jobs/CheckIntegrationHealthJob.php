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
use App\Support\SystemLog;
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
 *   vigiladas, o
 * - su webhook está degradado: sin la Secret Key de Samsara (rechaza todo)
 *   o rechazando firmas sin ningún válido después. Se evalúa aparte del feed
 *   de posiciones: el feed vivo no prueba que los pánicos (que sólo llegan
 *   por webhook) estén entrando.
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
                    TenantContext::for($integration->team_id, fn () => $this->inspect($integration, $sendNotification));
                }
            }));
    }

    private function inspect(TenantIntegration $integration, SendNotification $sendNotification): void
    {
        $teamId = $integration->team_id;

        $hasMonitoredFleet = Asset::query()->where('team_id', $teamId)->monitored()->exists();

        if (! $hasMonitoredFleet) {
            return;
        }

        $this->inspectWebhooks($integration, $sendNotification);

        if ($integration->status === TenantIntegrationStatus::Error) {
            $since = $integration->last_error_at ?? $integration->updated_at ?? now();

            $this->alert(
                $sendNotification,
                $integration,
                eventKey: sprintf('integration_error:%d:%d', $integration->id, $since->getTimestamp()),
                subject: "La integración {$integration->name} dejó de responder",
                body: 'SAM no puede leer tu flota desde el proveedor'
                    .(! in_array($integration->last_error_message, [null, '', '0'], true) ? " ({$integration->last_error_message})" : '')
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

        // handle() sólo recorre integraciones con last_sync_at: sin ancla no
        // hay episodio que fechar, así que no se avisa.
        if ($anchor === null) {
            return;
        }

        $this->alert(
            $sendNotification,
            $integration,
            eventKey: sprintf('integration_silent:%d:%d', $integration->id, $anchor->getTimestamp()),
            subject: "Sin datos de {$integration->name} desde hace más de {$silenceMinutes} min",
            body: 'Tu flota vigilada no está enviando posiciones ni eventos a SAM'
                .($lastData !== null ? ' desde el '.$lastData->copy()->setTimezone((string) config('billing.timezone', 'America/Mexico_City'))->format('d/m H:i') : '')
                .'. Puede ser el proveedor, el token o los webhooks: revisa Integraciones.',
        );
    }

    /**
     * Salud de webhooks por firma (WebhookEndpoint::signatureHealth). Un aviso
     * por episodio: el ancla es el último válido o la última configuración
     * de la llave, lo que sea más reciente.
     */
    private function inspectWebhooks(TenantIntegration $integration, SendNotification $sendNotification): void
    {
        // webhook_endpoints no lleva team_id: el tenant lo acota la integración.
        $endpoints = WebhookEndpoint::query()
            ->where('tenant_integration_id', $integration->id)
            ->where('status', 'active')
            ->get();

        foreach ($endpoints as $endpoint) {
            $health = $endpoint->signatureHealth();

            if (! in_array($health, [WebhookEndpoint::HEALTH_PENDING_SECRET, WebhookEndpoint::HEALTH_REJECTING], true)) {
                continue;
            }

            SystemLog::degraded('integrations.health.webhook_degraded', reason: $health, input: [
                'team_id' => $integration->team_id,
                'integration_id' => $integration->id,
                'webhook_endpoint_id' => $endpoint->id,
                'last_rejection_reason' => $endpoint->last_rejection_reason,
            ], calc: [
                'secret_configured' => $endpoint->hasSecret(),
                'last_valid_received_at' => $endpoint->last_valid_received_at?->toIso8601String(),
                'last_rejected_at' => $endpoint->last_rejected_at?->toIso8601String(),
                'rejection_window_hours' => WebhookEndpoint::REJECTION_WINDOW_HOURS,
            ]);

            if ($health === WebhookEndpoint::HEALTH_PENDING_SECRET) {
                $this->alert(
                    $sendNotification,
                    $integration,
                    eventKey: sprintf('integration_webhook_secret:%d:%d', $integration->id, $endpoint->id),
                    subject: "Falta la Secret Key del webhook de {$integration->name}",
                    body: 'SAM rechaza todos los webhooks de este proveedor (incluidos los pánicos) hasta que copies '
                        .'la Secret Key que generó al crear el webhook. Configúrala en Integraciones.',
                );

                continue;
            }

            $anchor = collect([$endpoint->last_valid_received_at, $endpoint->secret_configured_at, $endpoint->created_at])
                ->filter()
                ->sortDesc()
                ->first();

            $this->alert(
                $sendNotification,
                $integration,
                eventKey: sprintf('integration_webhook_rejected:%d:%d:%d', $integration->id, $endpoint->id, $anchor?->getTimestamp() ?? 0),
                subject: "SAM está rechazando los webhooks de {$integration->name}",
                body: 'Los webhooks llegan con una firma que no coincide con la Secret Key guardada, así que no se '
                    .'procesan (incluidos los pánicos). Revisa que la Secret Key en Integraciones sea la del webhook actual.',
            );
        }
    }

    private function lastDataAt(TenantIntegration $integration): ?CarbonInterface
    {
        // Sólo webhooks con firma válida: un rechazo no prueba que llegue nada útil.
        $webhook = WebhookEndpoint::query()
            ->where('tenant_integration_id', $integration->id)
            ->max('last_valid_received_at');

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
        $recipients = IncidentSupervisors::recipients($integration->team_id);

        if ($recipients === []) {
            return;
        }

        $sendNotification->execute(
            teamId: $integration->team_id,
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
