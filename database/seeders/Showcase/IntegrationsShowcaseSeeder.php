<?php

namespace Database\Seeders\Showcase;

use App\Domains\Integrations\Enums\AuthType;
use App\Domains\Integrations\Enums\IntegrationProviderStatus;
use App\Domains\Integrations\Enums\IntegrationProviderType;
use App\Domains\Integrations\Enums\SyncStatus;
use App\Domains\Integrations\Enums\SyncType;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Enums\WebhookEventStatus;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\IntegrationSyncJob;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Domains\Integrations\Models\WebhookEvent;
use Illuminate\Support\Str;

/**
 * Integraciones con salud mixta: la conexión Samsara real del tenant (o una
 * simulada si no tiene), más una en error y otra pendiente, con historial
 * de sincronizaciones y webhooks rechazados.
 *
 * SEGURIDAD: toda integración creada aquí lleva `config_json.sync.enabled =
 * false` y un token ficticio, así el scheduler real nunca la sondea contra
 * Samsara. Las extra no están `active`, así que ningún poller las toma.
 *
 * Marcador: `config_json.showcase = true` en las integraciones creadas; los
 * sync jobs y webhook events sólo se crean si la integración no tiene.
 */
class IntegrationsShowcaseSeeder extends ShowcaseStep
{
    public function run(): void
    {
        $provider = IntegrationProvider::query()->firstOrCreate(
            ['code' => 'samsara'],
            [
                'name' => 'Samsara',
                'type' => IntegrationProviderType::Telematics,
                'status' => IntegrationProviderStatus::Active,
                'capabilities_json' => ['gps', 'diagnostics', 'driver_behavior'],
            ],
        );
        $this->ctx->provider = $provider;

        $primary = TenantIntegration::query()
            ->where('team_id', $this->ctx->team->id)
            ->where('provider_id', $provider->id)
            ->where('status', TenantIntegrationStatus::Active)
            ->orderBy('id')
            ->first()
            ?? $this->createIntegration($provider, 'Samsara — flota principal', TenantIntegrationStatus::Active);
        $this->ctx->integration = $primary;

        $this->ensureWebhookEndpoint($primary, 'active', $this->ctx->now->subMinutes(7));
        $this->seedSyncJobs($primary, failureRate: 0.04);
        $this->seedRejectedWebhooks($primary);

        if ($this->ctx->light) {
            return;
        }

        $legacy = $this->findOrCreateExtra($provider, 'Samsara — cámaras (cuenta anterior)', TenantIntegrationStatus::Error, [
            'last_error_at' => $this->ctx->now->subHours(3),
            'last_error_message' => '401 Unauthorized: el token de API fue revocado en Samsara. Genera uno nuevo y actualiza la integración.',
            'last_sync_at' => $this->ctx->now->subDays(4),
        ]);
        $this->ensureWebhookEndpoint($legacy, 'inactive', $this->ctx->now->subDays(4));
        $this->seedSyncJobs($legacy, failureRate: 0.6);

        $this->findOrCreateExtra($provider, 'Samsara — sucursal Saltillo', TenantIntegrationStatus::Pending, [
            'last_sync_at' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function findOrCreateExtra(IntegrationProvider $provider, string $name, TenantIntegrationStatus $status, array $extra): TenantIntegration
    {
        return TenantIntegration::query()
            ->where('team_id', $this->ctx->team->id)
            ->where('name', $name)
            ->first()
            ?? $this->createIntegration($provider, $name, $status, $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function createIntegration(IntegrationProvider $provider, string $name, TenantIntegrationStatus $status, array $extra = []): TenantIntegration
    {
        $integration = TenantIntegration::query()->create([
            'team_id' => $this->ctx->team->id,
            'provider_id' => $provider->id,
            'name' => $name,
            'status' => $status,
            'auth_type' => AuthType::ApiKey,
            'credentials_encrypted' => 'showcase-token-sin-valor-real',
            'config_json' => [
                'showcase' => true,
                'sync' => ['enabled' => false, 'poll_safety_events' => false],
            ],
            'last_sync_at' => $this->ctx->now->subMinutes(12),
            'last_location_poll_at' => $status === TenantIntegrationStatus::Active ? $this->ctx->now->subMinutes(1) : null,
            'last_telemetry_poll_at' => $status === TenantIntegrationStatus::Active ? $this->ctx->now->subMinutes(4) : null,
            'created_at' => $this->ctx->startDay()->subMonths(6),
            ...$extra,
        ]);
        $this->ctx->count('tenant_integrations');

        return $integration;
    }

    private function ensureWebhookEndpoint(TenantIntegration $integration, string $status, \DateTimeInterface $lastReceivedAt): void
    {
        if (WebhookEndpoint::query()->where('tenant_integration_id', $integration->id)->exists()) {
            return;
        }

        WebhookEndpoint::query()->create([
            'tenant_integration_id' => $integration->id,
            'status' => $status,
            // Sandbox de demo: una llave propia (el replay firma con ella) y
            // salud por firma coherente con la última recepción.
            'secret' => Str::random(64),
            'secret_configured_at' => $this->ctx->now->subDays(30),
            'last_received_at' => $lastReceivedAt,
            'last_valid_received_at' => $lastReceivedAt,
        ]);
        $this->ctx->count('webhook_endpoints');
    }

    private function seedSyncJobs(TenantIntegration $integration, float $failureRate): void
    {
        if (IntegrationSyncJob::query()->where('tenant_integration_id', $integration->id)->exists()) {
            return;
        }

        $random = $this->ctx->random('sync-jobs', (string) $integration->id);
        $rows = [];
        $days = min($this->ctx->days, 30);

        for ($day = $days - 1; $day >= 0; $day--) {
            foreach ([SyncType::Full, SyncType::Incremental, SyncType::Incremental] as $i => $type) {
                $started = $this->ctx->now->subDays($day)->startOfDay()->addHours(2 + $i * 8)->addMinutes($random->int(0, 20));

                if ($started->greaterThan($this->ctx->now)) {
                    continue;
                }

                $failed = $random->chance($failureRate);
                $rows[] = [
                    'tenant_integration_id' => $integration->id,
                    'type' => $type,
                    'status' => $failed ? SyncStatus::Failed : SyncStatus::Completed,
                    'started_at' => $started,
                    'finished_at' => $started->addSeconds($random->int(4, $type === SyncType::Full ? 180 : 25)),
                    'records_processed' => $failed ? 0 : ($type === SyncType::Full ? $random->int(420, 520) : $random->int(3, 60)),
                    'error_message' => $failed ? $random->pick([
                        'Samsara API respondió 429 Too Many Requests; se reintentará en el siguiente ciclo.',
                        'Timeout de 30 s leyendo /fleet/vehicles (página 3).',
                        '401 Unauthorized: token inválido o revocado.',
                    ]) : null,
                    'created_at' => $started,
                    'updated_at' => $started,
                ];
            }
        }

        $this->bulkInsert('integration_sync_jobs', $rows, timestamps: false);
    }

    /**
     * Webhooks rechazados: firma inválida (un secret rotado a medias) y uno
     * que falló al procesarse. Quedan como evidencia en `webhook_events`.
     */
    private function seedRejectedWebhooks(TenantIntegration $integration): void
    {
        $exists = WebhookEvent::query()
            ->where('team_id', $this->ctx->team->id)
            ->where('signature', 'like', 'showcase%')
            ->exists();

        if ($exists) {
            return;
        }

        $cases = [
            [WebhookEventStatus::InvalidSignature, 'Firma HMAC inválida: el secret del endpoint no coincide (¿se rotó en Samsara?).', 30],
            [WebhookEventStatus::InvalidSignature, 'Firma HMAC inválida: timestamp fuera de la ventana de 5 minutos.', 21],
            [WebhookEventStatus::Failed, 'Payload sin data.conditions: no se pudo mapear el AlertIncident.', 9],
            [WebhookEventStatus::Processed, null, 2],
        ];

        foreach ($cases as $i => [$status, $error, $daysAgo]) {
            $received = $this->ctx->now->subDays(min($daysAgo, $this->ctx->days - 1))->setTime(14, 10 + $i);
            $payload = [
                'eventId' => sprintf('showcase-webhook-%d-%d', $this->ctx->team->id, $i),
                'eventType' => 'AlertIncident',
                'eventTime' => $received->toIso8601String(),
                'data' => ['conditions' => [['description' => 'Panic Button']]],
            ];

            WebhookEvent::query()->create([
                'team_id' => $this->ctx->team->id,
                'provider_id' => $integration->provider_id,
                'event_type' => 'AlertIncident',
                'payload_json' => $payload,
                'raw_payload' => json_encode($payload),
                'signature' => 'showcase-v1='.hash('sha256', (string) $i),
                'signature_timestamp' => (string) $received->getTimestampMs(),
                'received_at' => $received,
                'processed_at' => $received->addSeconds(1),
                'status' => $status,
                'error_message' => $error,
            ]);
            $this->ctx->count('webhook_events');
        }
    }
}
