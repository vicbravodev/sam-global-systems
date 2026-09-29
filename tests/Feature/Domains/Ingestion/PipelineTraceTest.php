<?php

namespace Tests\Feature\Domains\Ingestion;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Ingestion\Jobs\ProcessRawEventJob;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Domains\Normalization\Events\EventNormalized;
use App\Domains\Normalization\Jobs\NormalizeEventJob;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventMappingRule;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use App\Models\User;
use App\Support\PipelineTrace;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Un evento se sigue de punta a punta por su `trace_id` (App\Support\PipelineTrace):
 * cada job del recorrido lo ve en su Context junto al `team_id` del evento, dos
 * tenants nunca comparten traza, y un reintento o un replay conserva la original.
 */
class PipelineTraceTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    /** Todas las colas con nombre del worker (config/horizon.php). */
    private const array QUEUES = [
        'broadcasts', 'telematics', 'ingestion', 'normalization', 'decisions', 'incidents', 'context',
        'ai-evaluation', 'automation', 'notifications', 'billing', 'sync', 'default', 'audit', 'analytics',
    ];

    private IntegrationProvider $provider;

    /**
     * Context que vio cada job al terminar, en orden de ejecución.
     *
     * @var list<array{job: string, trace_id: mixed, team_id: mixed, tenant_id: mixed}>
     */
    private array $jobs = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->seed(IncidentsSeeder::class);

        $this->provider = IntegrationProvider::factory()->samsara()->create();

        $emergency = EventCategory::factory()->emergency()->create();
        $panic = EventType::factory()->create([
            'code' => 'panic_button',
            'category_id' => $emergency->id,
            'default_severity_id' => EventSeverity::factory()->critical()->create()->id,
        ]);

        EventMappingRule::factory()->create([
            'provider_id' => $this->provider->id,
            'external_event_type' => 'PanicButton',
            'mapped_event_type_id' => $panic->id,
        ]);

        Event::listen(JobProcessed::class, function (JobProcessed $event): void {
            $this->jobs[] = [
                'job' => $event->job->resolveName(),
                'trace_id' => Context::get(PipelineTrace::TRACE_KEY),
                'team_id' => Context::get(PipelineTrace::TEAM_KEY),
                'tenant_id' => Context::get('tenant_id'),
            ];
        });
    }

    public function test_a_samsara_webhook_keeps_one_trace_and_its_team_across_every_job(): void
    {
        [$team, $endpoint] = $this->tenantWithEndpoint('a');

        $this->postPanic($endpoint, 'evt-a')->assertStatus(202);

        $rawEvent = RawEvent::withoutGlobalScopes()->sole();
        $normalized = NormalizedEvent::withoutGlobalScopes()->sole();

        $this->assertNotNull($rawEvent->trace_id);
        $this->assertSame($rawEvent->trace_id, $normalized->trace_id);
        $this->assertSame(1, Incident::withoutGlobalScopes()->where('team_id', $team->id)->count());

        $ran = array_column($this->jobs, 'job');
        $this->assertContains(ProcessRawEventJob::class, $ran);
        $this->assertContains(NormalizeEventJob::class, $ran);
        $this->assertGreaterThanOrEqual(5, count($ran), 'El pánico debe recorrer ingesta → normalización → contexto → incidente → ...');

        foreach ($this->jobs as $job) {
            $this->assertSame($rawEvent->trace_id, $job['trace_id'], "{$job['job']} perdió la traza del evento.");
            $this->assertSame($team->id, $job['team_id'], "{$job['job']} vio otro team_id en la traza.");
            $this->assertSame($team->id, $job['tenant_id'], "{$job['job']} corrió fuera del tenant del evento.");
        }
    }

    public function test_two_tenants_in_the_same_worker_never_share_trace_or_team(): void
    {
        config(['queue.default' => 'database']);
        [$teamA, $endpointA] = $this->tenantWithEndpoint('a');
        [$teamB, $endpointB] = $this->tenantWithEndpoint('b');

        // Cada webhook llega en su propia petición (proceso nuevo en FPM)...
        $this->postPanic($endpointA, 'evt-a')->assertStatus(202);
        Context::flush();
        $this->postPanic($endpointB, 'evt-b')->assertStatus(202);
        Context::flush();

        // ...pero un mismo worker procesa los jobs de ambos, intercalados.
        $this->artisan('queue:work', [
            'connection' => 'database',
            '--queue' => implode(',', self::QUEUES),
            '--stop-when-empty' => true,
            '--sleep' => 0,
            '--memory' => 2048,
        ])->assertSuccessful();

        $traces = [
            $teamA->id => RawEvent::withoutGlobalScopes()->where('team_id', $teamA->id)->sole()->trace_id,
            $teamB->id => RawEvent::withoutGlobalScopes()->where('team_id', $teamB->id)->sole()->trace_id,
        ];

        $this->assertNotSame($traces[$teamA->id], $traces[$teamB->id]);
        $this->assertSame(
            $traces[$teamB->id],
            NormalizedEvent::withoutGlobalScopes()->where('team_id', $teamB->id)->sole()->trace_id,
        );

        $seen = [$teamA->id => [], $teamB->id => []];

        foreach ($this->jobs as $job) {
            $this->assertContains($job['team_id'], [$teamA->id, $teamB->id], "{$job['job']} corrió sin team_id en la traza.");
            $this->assertSame($traces[$job['team_id']], $job['trace_id'], "{$job['job']} mezcló la traza de un tenant con el team_id de otro.");
            $this->assertSame($job['team_id'], $job['tenant_id'], "{$job['job']} corrió en otro tenant que el de su traza.");
            $seen[$job['team_id']][] = $job['job'];
        }

        foreach ($seen as $jobs) {
            $this->assertContains(ProcessRawEventJob::class, $jobs);
            $this->assertContains(NormalizeEventJob::class, $jobs);
        }
    }

    public function test_tracing_tenant_b_does_not_touch_tenant_a(): void
    {
        [, $endpointA] = $this->tenantWithEndpoint('a');
        [$teamB, $endpointB] = $this->tenantWithEndpoint('b');
        $this->postPanic($endpointA, 'evt-a')->assertStatus(202);
        $traceA = RawEvent::withoutGlobalScopes()->sole()->trace_id;

        $this->jobs = [];

        // Mismo proceso, sin limpiar el Context entre webhooks (modo sync).
        $this->assertNoTenantLeak($teamB, fn () => $this->postPanic($endpointB, 'evt-b')->assertStatus(202));

        $traceB = RawEvent::withoutGlobalScopes()->where('team_id', $teamB->id)->sole()->trace_id;
        $this->assertNotSame($traceA, $traceB);
        $this->assertNotEmpty($this->jobs);

        foreach ($this->jobs as $job) {
            $this->assertSame([$traceB, $teamB->id, $teamB->id], [$job['trace_id'], $job['team_id'], $job['tenant_id']], "{$job['job']} heredó contexto de A.");
        }
    }

    public function test_a_replay_from_another_trace_adopts_the_trace_persisted_on_the_event(): void
    {
        [$team, $endpoint] = $this->tenantWithEndpoint('a');
        $this->postPanic($endpoint, 'evt-a');
        $rawEvent = RawEvent::withoutGlobalScopes()->sole();
        $this->jobs = [];

        // Un operador (u otro proceso) re-despacha la normalización desde una
        // traza distinta y de otro tenant: el job vuelve a la del evento.
        Context::flush();
        PipelineTrace::begin(Team::factory()->create()->id);
        NormalizeEventJob::dispatch($rawEvent->id);

        $this->assertNotEmpty($this->jobs);

        foreach ($this->jobs as $job) {
            $this->assertSame($rawEvent->trace_id, $job['trace_id'], "{$job['job']} no adoptó la traza persistida.");
            $this->assertSame($team->id, $job['team_id']);
        }
    }

    public function test_a_queue_retry_keeps_the_original_trace(): void
    {
        config(['queue.default' => 'database']);
        [$team, $endpoint] = $this->tenantWithEndpoint('a');

        // El primer intento de la normalización falla (fallo transitorio).
        $fail = true;
        Event::listen(EventNormalized::class, function () use (&$fail): void {
            if ($fail) {
                $fail = false;

                throw new RuntimeException('Fallo transitorio');
            }
        });

        $this->postPanic($endpoint, 'evt-a')->assertStatus(202);
        $this->work('ingestion'); // ProcessWebhookEventJob: guarda el RawEvent.
        $this->work('ingestion'); // ProcessRawEventJob: encola la normalización.
        $this->work('normalization'); // NormalizeEventJob: falla y se re-encola.

        $rawEvent = RawEvent::withoutGlobalScopes()->sole();
        $this->assertSame(1, DB::table('jobs')->where('queue', 'normalization')->count());

        // El worker del reintento arranca con otra traza en memoria: sólo el
        // payload del job (y el evento persistido) pueden devolverle la suya.
        Context::flush();
        PipelineTrace::begin(null);
        $this->jobs = [];
        $this->travel(5)->minutes();

        $this->work('normalization');

        $this->assertSame(NormalizeEventJob::class, $this->jobs[0]['job'] ?? null, 'El reintento no corrió.');
        $this->assertSame($rawEvent->trace_id, $this->jobs[0]['trace_id']);
        $this->assertSame($team->id, $this->jobs[0]['team_id']);
        $this->assertSame($rawEvent->trace_id, NormalizedEvent::withoutGlobalScopes()->sole()->trace_id);
    }

    private function work(string $queue): void
    {
        $this->artisan('queue:work', [
            'connection' => 'database',
            '--queue' => $queue,
            '--once' => true,
            '--sleep' => 0,
            '--memory' => 2048,
        ])->assertSuccessful();
    }

    /**
     * @return array{0: Team, 1: WebhookEndpoint}
     */
    private function tenantWithEndpoint(string $suffix): array
    {
        $team = User::factory()->create()->currentTeam;

        $integration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'provider_id' => $this->provider->id,
            'name' => "Samsara {$suffix}",
            'auth_type' => 'api_key',
            'credentials_encrypted' => 'test-key',
            'status' => TenantIntegrationStatus::Active,
        ]);

        $asset = Asset::factory()->create(['team_id' => $team->id]);
        AssetExternalReference::factory()->create([
            'asset_id' => $asset->id,
            'provider_id' => $this->provider->id,
            'external_id' => "vehicle-{$suffix}",
        ]);

        $endpoint = WebhookEndpoint::factory()->create([
            'tenant_integration_id' => $integration->id,
            'url' => "trace-{$suffix}-".bin2hex(random_bytes(4)),
            'status' => 'active',
        ]);

        return [$team, $endpoint];
    }

    /**
     * Pánico firmado como lo firma Samsara (X-Samsara-Signature / -Timestamp).
     */
    private function postPanic(WebhookEndpoint $endpoint, string $eventId): TestResponse
    {
        $suffix = str_starts_with($endpoint->url, 'trace-a') ? 'a' : 'b';
        $body = [
            'eventId' => $eventId,
            'eventType' => 'PanicButton',
            'eventTime' => now()->toIso8601String(),
            'asset' => ['id' => "vehicle-{$suffix}"],
        ];
        $rawPayload = (string) json_encode($body);
        $timestamp = (string) now()->getTimestampMs();

        return $this->call('POST', "/api/webhooks/{$endpoint->url}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SAMSARA_SIGNATURE' => 'v1='.hash_hmac('sha256', 'v1:'.$timestamp.':'.$rawPayload, $endpoint->secret),
            'HTTP_X_SAMSARA_TIMESTAMP' => $timestamp,
        ], $rawPayload);
    }
}
