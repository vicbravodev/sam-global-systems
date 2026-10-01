<?php

namespace Database\Seeders\Showcase;

use App\Domains\AI\Jobs\EvaluateEventJob;
use App\Domains\AI\Jobs\ReevaluateEventJob;
use App\Domains\Audit\Jobs\WriteAuditLogJob;
use App\Domains\Context\Jobs\EnrichContextJob;
use App\Domains\Decisions\Jobs\ReevaluateDecisionJob;
use App\Domains\Decisions\Jobs\RunDecisionEngineJob;
use App\Domains\Incidents\Jobs\AutoAssignIncidentJob;
use App\Domains\Incidents\Jobs\CreateIncidentJob;
use App\Domains\Ingestion\Actions\IngestSafetyEvent;
use App\Domains\Ingestion\Jobs\ProcessRawEventJob;
use App\Domains\Integrations\Actions\HandleWebhook;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Jobs\ProcessWebhookEventJob;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Domains\Normalization\Jobs\NormalizeEventJob;
use App\Models\Team;
use App\Support\TenantContext;
use Database\Seeders\Showcase\Support\ShowcaseSandbox;
use Illuminate\Bus\Dispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use RuntimeException;
use Throwable;

/**
 * `sam:showcase --replay=N`: reinyecta los N eventos REALES más recientes de
 * los fixtures de Samsara (`database/fixtures/samsara-*.json`, ver
 * `samsara:export-fixtures`) por el pipeline real — webhook firmado o feed de
 * safety events — y lo procesa EN SÍNCRONO dentro del sandbox:
 *
 *   webhook → RawEvent → normalización → contexto → IA (agente Null, sin
 *   red) → decisión → incidente
 *
 * La cola está falseada: se ejecutan a mano SÓLO los jobs de la lista
 * `PIPELINE_JOBS` (todos escriben en la DB local). Todo lo que saldría del
 * proceso — notificaciones, llamadas de verificación, descarga de media de
 * Samsara, sincronizaciones — queda capturado y se descarta; por eso las
 * notificaciones del replay se quedan en `pending`.
 *
 * Cada evento recibe un id nuevo determinista (`showcase-replay-…`) y su
 * hora se corre a "hace unos minutos": re-ejecutar el replay no duplica
 * (el pipeline lo marca como duplicado).
 */
class ShowcaseReplayer
{
    /** @var array<int, class-string> */
    private const PIPELINE_JOBS = [
        ProcessWebhookEventJob::class,
        ProcessRawEventJob::class,
        NormalizeEventJob::class,
        EnrichContextJob::class,
        EvaluateEventJob::class,
        ReevaluateEventJob::class,
        RunDecisionEngineJob::class,
        ReevaluateDecisionJob::class,
        CreateIncidentJob::class,
        AutoAssignIncidentJob::class,
        WriteAuditLogJob::class,
    ];

    /** @var array<int, string> */
    private array $failures = [];

    /** @var \SplObjectStorage<object, null> */
    private \SplObjectStorage $alreadyQueued;

    /**
     * @return array{replayed: int, jobs: int, discarded: int, failures: array<int, string>}
     */
    public function replay(string $teamSlug, int $count, ?Command $command = null): array
    {
        if (app()->isProduction()) {
            throw new RuntimeException('El replay del showcase nunca corre en producción.');
        }

        ShowcaseSandbox::enter();

        // Lo que ya capturó la cola falsa (p.ej. durante la siembra) no es del replay.
        $this->alreadyQueued = new \SplObjectStorage;

        foreach ($this->pushedJobs() as $pushes) {
            foreach ($pushes as $push) {
                if (is_object($push['job'])) {
                    $this->alreadyQueued->offsetSet($push['job']);
                }
            }
        }

        $team = Team::query()->where('slug', $teamSlug)->firstOrFail();
        $events = array_slice($this->fixtures(), 0, max(0, $count));
        $replayed = 0;
        $stats = ['jobs' => 0, 'discarded' => 0];

        TenantContext::for($team->id, function () use ($team, $events, $command, &$replayed) {
            $integration = TenantIntegration::query()
                ->where('team_id', $team->id)
                ->where('status', TenantIntegrationStatus::Active)
                ->whereHas('provider', fn ($q) => $q->where('code', 'samsara'))
                ->orderBy('id')
                ->first();
            $endpoint = $integration !== null
                ? WebhookEndpoint::query()->where('tenant_integration_id', $integration->id)->where('status', 'active')->first()
                : null;

            if ($integration === null) {
                $command?->warn('Replay omitido: el tenant no tiene una integración Samsara activa.');

                return;
            }

            foreach ($events as $i => $fixture) {
                $at = now()->subMinutes(4 + $i * 6);

                if (($fixture['kind'] ?? 'webhook') === 'safety_event') {
                    $payload = $this->shiftSafetyEvent($fixture['raw_payload'], $at);
                    app(IngestSafetyEvent::class)->execute($integration, $payload);
                } elseif ($endpoint !== null) {
                    $body = $this->shiftWebhook($fixture['raw_payload'], $at);
                    $raw = (string) json_encode($body);
                    $timestamp = (string) now()->getTimestampMs();
                    $signature = 'v1='.hash_hmac('sha256', 'v1:'.$timestamp.':'.$raw, (string) $endpoint->secret);
                    app(HandleWebhook::class)->execute($endpoint, (string) ($body['eventType'] ?? 'unknown'), $body, $raw, $signature, $timestamp);
                } else {
                    continue;
                }

                $replayed++;
                $command?->line(sprintf('  replay %s · %s', $fixture['event_type'] ?? 'evento', $fixture['vehicle_name'] ?? ''));
            }
        });

        $stats = $this->drain($team->id);

        foreach ($this->failures as $failure) {
            $command?->warn('  job fallido: '.$failure);
        }

        return ['replayed' => $replayed, 'jobs' => $stats['jobs'], 'discarded' => $stats['discarded'], 'failures' => $this->failures];
    }

    /**
     * Fixtures reales, alternando archivos (pánico, alertas, safety events)
     * y del más reciente al más antiguo dentro de cada uno, para que un
     * replay corto ya muestre variedad.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fixtures(): array
    {
        $groups = [];

        $files = glob(database_path('fixtures/samsara-*.json'));

        foreach ($files === false ? [] : $files as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            $entries = array_values(array_filter(is_array($decoded) ? $decoded : [], fn ($entry) => is_array($entry['raw_payload'] ?? null)));
            usort($entries, fn (array $a, array $b) => strcmp((string) ($b['occurred_at'] ?? ''), (string) ($a['occurred_at'] ?? '')));

            if ($entries !== []) {
                $groups[] = $entries;
            }
        }

        $all = [];

        for ($i = 0; $groups !== []; $i++) {
            foreach ($groups as $g => $entries) {
                if (! isset($entries[$i])) {
                    unset($groups[$g]);

                    continue;
                }

                $all[] = $entries[$i];
            }
        }

        return $all;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function shiftWebhook(array $body, \DateTimeInterface $at): array
    {
        $body['eventId'] = $this->replayId((string) ($body['eventId'] ?? json_encode($body)));
        $body['eventTime'] = $at->format('Y-m-d\TH:i:s.v\Z');

        if (isset($body['data']) && is_array($body['data'])) {
            $body['data']['happenedAtTime'] = $at->format('Y-m-d\TH:i:s\Z');
            $body['data']['updatedAtTime'] = $at->format('Y-m-d\TH:i:s\Z');
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function shiftSafetyEvent(array $payload, \DateTimeInterface $at): array
    {
        $payload['id'] = $this->replayId((string) ($payload['id'] ?? json_encode($payload)));
        $payload['createdAtTime'] = $at->format('Y-m-d\TH:i:s\Z');
        $payload['updatedAtTime'] = $at->format('Y-m-d\TH:i:s\Z');
        unset($payload['media'], $payload['downloadForwardVideoUrl'], $payload['downloadInwardVideoUrl'], $payload['downloadTrackedInwardVideoUrl']);

        return $payload;
    }

    private function replayId(string $original): string
    {
        $hex = md5('showcase-replay:'.$original);

        return sprintf('showcase-replay-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 12));
    }

    /**
     * Jobs capturados por la cola falsa que instala `ShowcaseSandbox::enter()`.
     *
     * @return array<string, array<int, array{job: mixed, queue: mixed, data: mixed}>>
     */
    private function pushedJobs(): array
    {
        $queue = Queue::getFacadeRoot();

        if (! $queue instanceof QueueFake) {
            throw new RuntimeException('El replay del showcase necesita la cola falsa del sandbox (ShowcaseSandbox::enter()).');
        }

        return $queue->pushedJobs();
    }

    /**
     * Ejecuta en síncrono los jobs del pipeline que la cola falsa capturó,
     * hasta que no aparezcan nuevos.
     *
     * @return array{jobs: int, discarded: int}
     */
    private function drain(int $teamId): array
    {
        $seen = $this->alreadyQueued;
        $jobs = 0;
        $discarded = 0;

        do {
            $progress = false;

            foreach ($this->pushedJobs() as $pushes) {
                foreach ($pushes as $push) {
                    $job = $push['job'];

                    if (! is_object($job) || $seen->offsetExists($job)) {
                        continue;
                    }

                    $seen->offsetSet($job);

                    if (! in_array($job::class, self::PIPELINE_JOBS, true)) {
                        $discarded++;

                        continue;
                    }

                    $progress = true;

                    try {
                        DB::transaction(fn () => TenantContext::for($teamId, fn () => app(Dispatcher::class)->dispatchNow($job)));
                        $jobs++;
                    } catch (Throwable $e) {
                        $this->failures[] = class_basename($job).': '.$e->getMessage();
                    }
                }
            }
        } while ($progress && $jobs < 1_000);

        return ['jobs' => $jobs, 'discarded' => $discarded];
    }
}
