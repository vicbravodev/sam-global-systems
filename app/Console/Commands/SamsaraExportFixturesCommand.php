<?php

namespace App\Console\Commands;

use App\Domains\Ingestion\Enums\EventSourceType;
use App\Domains\Ingestion\Models\EventSource;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Integrations\Adapters\SamsaraAdapter;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\Team;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * Exporta los payloads REALES más recientes de Samsara de un tenant a
 * `database/fixtures/samsara-*.json`, el formato que consumen
 * `samsara:replay` y `sam:showcase --replay=N`:
 *
 *  - samsara-panic-events.json   → AlertIncident de botón de pánico (webhook)
 *  - samsara-alert-events.json   → el resto de AlertIncident (cámara obstruida, manipulación…)
 *  - samsara-safety-events.json  → safety events del feed `/safety-events/stream`
 *
 * Fuente: `raw_events` del tenant (lo que ya recibió); con `--from-api`
 * además lee (sólo lectura) los safety events recientes de la API de Samsara
 * con la integración del tenant.
 *
 * NUNCA exporta secretos: ni cabeceras (firmas HMAC), ni credenciales, ni
 * URLs pre-firmadas de media (llevan firma y caducan en minutos).
 */
class SamsaraExportFixturesCommand extends Command
{
    protected $signature = 'samsara:export-fixtures
        {--team=serviexpress-jc : Slug del tenant cuyos eventos reales se exportan}
        {--limit=25 : Máximo de eventos por archivo}
        {--from-api : Además, lee los safety events recientes de la API de Samsara (sólo lectura)}
        {--output= : Directorio destino (por defecto database/fixtures)}
        {--days=1 : Ventana en días hacia atrás para --from-api (el feed pagina desde el inicio de la ventana)}';

    protected $description = 'Exporta eventos reales recientes de Samsara a database/fixtures/ para replays y el showcase.';

    /** Claves de payload con URLs pre-firmadas: nunca se exportan. */
    private const SIGNED_URL_KEYS = ['media', 'downloadForwardVideoUrl', 'downloadInwardVideoUrl', 'downloadTrackedInwardVideoUrl'];

    public function handle(SamsaraAdapter $adapter): int
    {
        $team = Team::query()->where('slug', $this->option('team'))->first();

        if ($team === null) {
            $this->error("No existe el tenant [{$this->option('team')}].");

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));

        $files = TenantContext::for($team->id, function () use ($team, $limit, $adapter) {
            $files = ['panic' => [], 'alert' => [], 'safety' => []];

            RawEvent::query()
                ->where('team_id', $team->id)
                ->whereHas('provider', fn ($q) => $q->where('code', 'samsara'))
                // Sólo lo que mandó Samsara (webhook / feed), no los monitores internos de SAM.
                ->whereIn('event_source_id', EventSource::query()
                    ->where('team_id', $team->id)
                    ->whereIn('source_type', [EventSourceType::Webhook->value, EventSourceType::PollingFeed->value])
                    ->where('source_name', 'not like', 'showcase-%')
                    ->select('id'))
                ->where(fn ($q) => $q->whereNull('external_event_id')->orWhere('external_event_id', 'not like', 'showcase-%'))
                ->whereNotIn('status', ['duplicate_detected', 'invalid_signature', 'malformed'])
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit($limit * 20)
                ->get()
                ->unique('external_event_id')
                ->each(function (RawEvent $raw) use (&$files, $limit) {
                    $payload = $raw->payload_json;
                    $kind = ($payload['eventType'] ?? null) === 'AlertIncident' ? 'webhook' : (isset($payload['behaviorLabels']) ? 'safety_event' : null);

                    if ($kind === null) {
                        return;
                    }

                    $bucket = $kind === 'safety_event'
                        ? 'safety'
                        : (Arr::get($payload, 'data.conditions.0.description') === 'Panic Button' ? 'panic' : 'alert');

                    if (count($files[$bucket]) < $limit) {
                        $files[$bucket][] = $this->entry($raw->id, $kind === 'webhook' ? 'webhook' : 'polling_feed', $kind, $payload, $raw->occurred_at?->toIso8601String());
                    }
                });

            if ($this->option('from-api')) {
                $files['safety'] = array_slice([...$this->fromApi($team, $adapter), ...$files['safety']], 0, $limit);
            }

            return $files;
        });

        // Mismo criterio que el `?:` previo: '' y '0' caen al directorio por defecto.
        $output = $this->option('output');
        $outputDirectory = in_array($output, [null, '', '0'], true) ? database_path('fixtures') : $output;

        foreach (['panic' => 'samsara-panic-events.json', 'alert' => 'samsara-alert-events.json', 'safety' => 'samsara-safety-events.json'] as $bucket => $name) {
            if ($files[$bucket] === []) {
                $this->line("  {$name}: sin eventos reales, no se toca.");

                continue;
            }

            file_put_contents(
                rtrim($outputDirectory, '/')."/{$name}",
                json_encode($files[$bucket], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
            );
            $this->info(sprintf('  %s: %d evento(s)', $name, count($files[$bucket])));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fromApi(Team $team, SamsaraAdapter $adapter): array
    {
        $integration = TenantIntegration::query()
            ->where('team_id', $team->id)
            ->where('status', TenantIntegrationStatus::Active)
            ->whereHas('provider', fn ($q) => $q->where('code', 'samsara'))
            ->first();

        if ($integration === null) {
            $this->warn('Sin integración Samsara activa: se omite --from-api.');

            return [];
        }

        $since = CarbonImmutable::now()->subDays(max(1, (int) $this->option('days')));
        $events = $adapter->fetchSafetyEvents($integration, null, $since)['events'];

        usort($events, fn (array $a, array $b) => strcmp((string) ($b['createdAtTime'] ?? ''), (string) ($a['createdAtTime'] ?? '')));
        $this->line(sprintf('  API Samsara: %d safety event(s) desde %s', count($events), $since->toDateString()));

        // Variedad antes que volumen: como mucho N por etiqueta, los más recientes primero.
        $perLabel = [];
        $picked = [];
        $cap = max(2, (int) ceil((int) $this->option('limit') / 4));

        foreach ($events as $event) {
            $label = (string) Arr::get($event, 'behaviorLabels.0.label', 'SafetyEvent');

            if (($perLabel[$label] = ($perLabel[$label] ?? 0) + 1) <= $cap) {
                $picked[] = $this->entry(null, 'api', 'safety_event', $event, $event['createdAtTime'] ?? null);
            }
        }

        return $picked;
    }

    /**
     * Misma forma que el fixture histórico de pánico, más `kind`.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function entry(?int $rawId, string $source, string $kind, array $payload, ?string $occurredAt): array
    {
        $payload = $this->sanitize($payload);
        $isSafety = $kind === 'safety_event';

        return [
            'id' => $rawId,
            'source' => $source,
            'kind' => $kind,
            'samsara_event_id' => $isSafety ? ($payload['id'] ?? null) : ($payload['eventId'] ?? null),
            'event_type' => $isSafety ? Arr::get($payload, 'behaviorLabels.0.label', 'SafetyEvent') : ($payload['eventType'] ?? null),
            'event_description' => $isSafety ? Arr::get($payload, 'behaviorLabels.0.name') : Arr::get($payload, 'data.conditions.0.description'),
            'vehicle_id' => $isSafety ? Arr::get($payload, 'asset.id') : Arr::get($payload, 'data.conditions.0.details.panicButton.vehicle.id'),
            'vehicle_name' => $isSafety ? Arr::get($payload, 'asset.name') : Arr::get($payload, 'data.conditions.0.details.panicButton.vehicle.name'),
            'driver_id' => Arr::get($payload, 'driver.id'),
            'driver_name' => Arr::get($payload, 'driver.name'),
            'occurred_at' => $occurredAt,
            'raw_payload' => $payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitize(array $payload): array
    {
        foreach (self::SIGNED_URL_KEYS as $key) {
            unset($payload[$key]);
        }

        array_walk_recursive($payload, function (&$value) {
            if (is_string($value) && str_contains($value, 'X-Amz-Signature')) {
                $value = null;
            }
        });

        return $payload;
    }
}
