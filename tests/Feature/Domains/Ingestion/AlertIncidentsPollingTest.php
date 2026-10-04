<?php

namespace Tests\Feature\Domains\Ingestion;

use App\Contracts\RawEventIngestion;
use App\Domains\Assets\Models\Asset;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Ingestion\Actions\IngestAlertIncident;
use App\Domains\Ingestion\Enums\EventSourceType;
use App\Domains\Ingestion\Enums\RawEventStatus;
use App\Domains\Ingestion\Jobs\PollAlertIncidentsJob;
use App\Domains\Ingestion\Jobs\PollSafetyEventsJob;
use App\Domains\Ingestion\Jobs\PollSamsaraAlertIncidentsJob;
use App\Domains\Ingestion\Models\PipelineFailureAlert;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Ingestion\Notifications\PipelineFailureNotification;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Database\Seeders\NormalizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Respaldo del webhook de pánico: PollAlertIncidentsJob lee
 * `GET /alerts/incidents/stream` de las alertas de pánico (trigger 1034) y
 * mete cada emergencia por el mismo pipeline que el webhook, deduplicando con
 * él de forma simétrica por la identidad del incidente.
 */
class AlertIncidentsPollingTest extends TestCase
{
    use AssertsSystemLog;
    use AssertsTenantIsolation;
    use RefreshDatabase;

    private const string VEHICLE_ID = '281474993505355';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('rustfs');
        Carbon::setTestNow('2026-10-02T12:00:00Z');

        $this->seed(NormalizationSeeder::class);
        $this->seed(IncidentsSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeIntegration(?Team $team = null, array $attributes = []): TenantIntegration
    {
        $team ??= User::factory()->create()->currentTeam;
        $provider = IntegrationProvider::query()->where('code', 'samsara')->firstOrFail();

        $integration = TenantIntegration::withoutGlobalScopes()->create(array_merge([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'name' => 'Samsara Fleet',
            'status' => 'active',
            'auth_type' => 'api_key',
            'credentials_encrypted' => '',
        ], $attributes));

        IntegrationCredential::create([
            'tenant_integration_id' => $integration->id,
            'key' => 'api_token',
            'value_encrypted' => 'sk-test-token',
        ]);

        return $integration->load('provider');
    }

    /**
     * Un incidente de alerta con la forma de `GetWorkflowIncidentResponseObject`.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function incident(array $overrides = [], string $description = 'Panic Button', string $happenedAt = '2026-10-02T11:55:00Z', ?int $triggerId = 1034): array
    {
        $ms = Carbon::parse($happenedAt)->getTimestampMs();

        return array_merge([
            'conditions' => [[
                'description' => $description,
                'triggerId' => $triggerId,
                'details' => ['panicButton' => [
                    'vehicle' => ['id' => self::VEHICLE_ID, 'name' => 'T-879', 'serial' => 'GYP5CUW97G'],
                    'driver' => ['id' => '51909883', 'name' => 'Chofer Prueba'],
                ]],
            ]],
            'configurationId' => 'cfg-panic',
            'happenedAtTime' => $happenedAt,
            'incidentUrl' => 'https://cloud.samsara.com/o/4006685/fleet/workflows/incidents/cfg-panic/1/'.self::VEHICLE_ID.'/'.$ms,
            'isResolved' => false,
            'updatedAtTime' => $happenedAt,
        ], $overrides);
    }

    /**
     * El mismo incidente tal como lo entrega el webhook (`AlertIncident`).
     *
     * @param  array<string, mixed>  $incident
     * @return array<string, mixed>
     */
    private function webhookPayload(array $incident, string $eventId = 'wh-evt-1'): array
    {
        return [
            'eventId' => $eventId,
            'eventTime' => $incident['happenedAtTime'],
            'eventType' => 'AlertIncident',
            'orgId' => 4006685,
            'webhookId' => '541115021054798',
            'data' => $incident,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function configurationsResponse(): array
    {
        return [
            'data' => [
                [
                    'id' => 'cfg-panic',
                    'name' => 'Botón de pánico',
                    'isEnabled' => true,
                    'createdAtTime' => '2026-01-01T00:00:00Z',
                    'lastModifiedAtTime' => '2026-01-01T00:00:00Z',
                    'actions' => [],
                    'scope' => ['all' => true],
                    'triggers' => [['triggerTypeId' => 1034]],
                ],
                [
                    'id' => 'cfg-speed',
                    'name' => 'Exceso de velocidad',
                    'isEnabled' => true,
                    'createdAtTime' => '2026-01-01T00:00:00Z',
                    'lastModifiedAtTime' => '2026-01-01T00:00:00Z',
                    'actions' => [],
                    'scope' => ['all' => true],
                    'triggers' => [['triggerTypeId' => 1000]],
                ],
            ],
            'pagination' => ['endCursor' => '', 'hasNextPage' => false],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $incidents
     */
    private function fakeSamsara(array $incidents, string $endCursor = 'cursor-1', bool $hasNextPage = false): void
    {
        Http::fake([
            'api.samsara.com/alerts/configurations*' => Http::response($this->configurationsResponse()),
            'api.samsara.com/alerts/incidents/stream*' => Http::response([
                'data' => $incidents,
                'pagination' => ['endCursor' => $endCursor, 'hasNextPage' => $hasNextPage],
            ]),
        ]);
    }

    private function poll(TenantIntegration $integration): void
    {
        app()->call([new PollAlertIncidentsJob($integration), 'handle']);
    }

    private function panicIncidents(Team $team): int
    {
        return Incident::withoutGlobalScopes()->where('team_id', $team->id)->count();
    }

    private function webhook(Team $team, array $incident, string $eventId = 'wh-evt-1'): void
    {
        app(RawEventIngestion::class)->ingest($team->id, 'samsara', 'AlertIncident', $this->webhookPayload($incident, $eventId));
    }

    public function test_poll_ingests_a_panic_the_webhook_never_delivered_and_opens_its_incident(): void
    {
        Notification::fake();
        $superAdmin = User::factory()->create(['global_role' => 'super_admin']);

        $integration = $this->makeIntegration();
        $team = $integration->team;
        $this->fakeSamsara([$this->incident()]);

        $this->poll($integration);

        $raw = RawEvent::withoutGlobalScopes()->where('team_id', $team->id)->sole();
        $this->assertSame(EventSourceType::PollingFeed, $raw->eventSource->source_type);
        $this->assertSame('AlertIncident', $raw->event_type_raw);
        $this->assertStringStartsWith('alert_incident:', (string) $raw->deduplication_key);
        $this->assertStringEndsWith(':open', (string) $raw->deduplication_key);
        $this->assertSame('2026-10-02T11:55:00+00:00', $raw->occurred_at?->toIso8601String());

        $this->assertSame(1, $this->panicIncidents($team), 'el pánico rescatado por el poll abre su incidente');

        // Cinco minutos sin webhook (gracia 120 s): el webhook está roto.
        $this->assertSystemLogged('ingestion.alert_incidents.webhook_missed', fn (array $c): bool => $c['reason'] === 'webhook_not_delivered'
            && $c['input']['raw_event_id'] === $raw->id
            && $c['calc']['grace_seconds'] === 120
            && $c['calc']['age_seconds'] === 300);
        $this->assertSame(1, PipelineFailureAlert::withoutGlobalScopes()->where('kind', PipelineFailureAlert::KIND_WEBHOOK_MISSED)->where('raw_event_id', $raw->id)->count());
        Notification::assertSentTo($superAdmin, PipelineFailureNotification::class);

        $this->assertSystemLogged('ingestion.alert_incidents.ingested', fn (array $c): bool => $c['result']['raw_event_id'] === $raw->id);
        $this->assertSystemLogged('ingestion.alert_incidents.cycle_completed', fn (array $c): bool => $c['result']['ingested'] === 1
            && $c['result']['already_ingested'] === 0);
        $this->assertNoSensitiveDataLogged();

        // El cursor se persiste; la próxima corrida no re-avisa (dedup por raw event).
        $state = $integration->fresh()->sync_state_json['alert_incidents'];
        $this->assertSame(['cfg-panic'], $state['configuration_ids']);
        $this->assertNull($state['cursor']);
        $this->assertNotNull($state['last_polled_at']);

        $this->poll($integration->fresh());
        $this->assertSame(1, RawEvent::withoutGlobalScopes()->where('team_id', $team->id)->count());
        $this->assertSame(1, PipelineFailureAlert::withoutGlobalScopes()->count());
    }

    public function test_a_fresh_panic_inside_the_webhook_grace_is_ingested_without_alerting(): void
    {
        Notification::fake();
        $integration = $this->makeIntegration();
        $this->fakeSamsara([$this->incident(happenedAt: '2026-10-02T11:59:30Z')]);

        $this->poll($integration);

        $this->assertSame(1, $this->panicIncidents($integration->team));
        $this->assertSystemLogged('ingestion.alert_incidents.webhook_check', fn (array $c): bool => $c['reason'] === 'within_grace'
            && $c['calc']['age_seconds'] === 30);
        $this->assertSystemNotLogged('ingestion.alert_incidents.webhook_missed');
        $this->assertSame(0, PipelineFailureAlert::withoutGlobalScopes()->count());
    }

    public function test_webhook_first_then_poll_yields_a_single_incident(): void
    {
        $integration = $this->makeIntegration();
        $team = $integration->team;
        $incident = $this->incident();

        $this->webhook($team, $incident);
        $this->assertSame(1, $this->panicIncidents($team));

        $this->fakeSamsara([$incident]);
        $this->poll($integration);

        $this->assertSame(1, RawEvent::withoutGlobalScopes()->where('team_id', $team->id)->count(), 'el poll no guarda otra copia');
        $this->assertSame(1, $this->panicIncidents($team));
        $this->assertSystemLogged('ingestion.alert_incidents.skipped', fn (array $c): bool => $c['reason'] === 'already_ingested'
            && $c['calc']['first_source'] === 'webhook');
        $this->assertSystemNotLogged('ingestion.alert_incidents.webhook_missed');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_poll_first_then_webhook_yields_a_single_incident(): void
    {
        $integration = $this->makeIntegration();
        $team = $integration->team;
        $incident = $this->incident(happenedAt: '2026-10-02T11:59:30Z');

        $this->fakeSamsara([$incident]);
        $this->poll($integration);
        $this->assertSame(1, $this->panicIncidents($team));

        // El webhook llega tarde con otro eventId (es otra entrega).
        $this->webhook($team, $incident, 'wh-late');

        $webhookRaw = RawEvent::withoutGlobalScopes()->where('team_id', $team->id)->where('external_event_id', 'wh-late')->sole();
        $this->assertSame(RawEventStatus::DuplicateDetected, $webhookRaw->fresh()->status);
        $this->assertSame(1, $this->panicIncidents($team));
        $this->assertSystemLogged('ingestion.duplicate.detected', fn (array $c): bool => $c['reason'] === 'cross_source_key'
            && $c['input']['raw_event_id'] === $webhookRaw->id);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_same_incident_url_with_a_different_happened_at_is_two_panics(): void
    {
        $integration = $this->makeIntegration();
        $team = $integration->team;
        $first = $this->incident();
        // Mismo incidentUrl (visto en simulaciones reales), otro instante: es otro pánico.
        $second = $this->incident(['incidentUrl' => $first['incidentUrl']], happenedAt: '2026-10-02T11:57:00Z');

        $this->webhook($team, $first, 'wh-1');
        $this->webhook($team, $second, 'wh-2');

        $raws = RawEvent::withoutGlobalScopes()->where('team_id', $team->id)->orderBy('id')->get();
        $this->assertCount(2, $raws);
        $this->assertNotSame($raws[0]->deduplication_key, $raws[1]->deduplication_key);
        $this->assertNotSame(RawEventStatus::DuplicateDetected, $raws[1]->fresh()->status);

        // El poll ve los dos: ambos ya están, no guarda copias.
        $this->fakeSamsara([$first, $second]);
        $this->poll($integration);

        $this->assertSame(2, RawEvent::withoutGlobalScopes()->where('team_id', $team->id)->count());
    }

    /**
     * El mismo pánico tal como lo ve otra alerta de Samsara con trigger 1034:
     * otra `configurationId` y otra `incidentUrl`, misma unidad e instante.
     *
     * @param  array<string, mixed>  $incident
     * @return array<string, mixed>
     */
    private function seenByAnotherAlert(array $incident, string $configurationId): array
    {
        return array_merge($incident, [
            'configurationId' => $configurationId,
            'incidentUrl' => str_replace('/cfg-panic/', '/'.$configurationId.'/', (string) $incident['incidentUrl']),
        ]);
    }

    public function test_one_press_fired_by_several_panic_alerts_is_delivered_once_by_the_webhook(): void
    {
        // Caso real (prod 2026-10-04): un pánico disparó cuatro alertas de
        // Samsara; sólo la de SAM apunta al webhook, que lo entregó en 7 s.
        // El poll, ya resuelto, vio las cuatro: no es un webhook roto.
        Notification::fake();
        User::factory()->create(['global_role' => 'super_admin']);
        $integration = $this->makeIntegration();
        $team = $integration->team;
        $open = $this->incident(happenedAt: '2026-10-02T11:57:18Z');

        $this->webhook($team, $open);
        $this->assertSame(1, $this->panicIncidents($team));

        $resolved = ['isResolved' => true, 'resolvedAtTime' => '2026-10-02T11:57:50Z', 'updatedAtTime' => '2026-10-02T11:57:50Z'];
        $this->fakeSamsara([
            array_merge($open, $resolved),
            array_merge($this->seenByAnotherAlert($open, 'cfg-client-a'), $resolved),
            array_merge($this->seenByAnotherAlert($open, 'cfg-client-b'), $resolved),
            array_merge($this->seenByAnotherAlert($open, 'cfg-client-c'), $resolved),
        ]);
        $this->poll($integration);

        $this->assertSystemNotLogged('ingestion.alert_incidents.webhook_missed');
        $this->assertSame(0, PipelineFailureAlert::withoutGlobalScopes()->where('kind', PipelineFailureAlert::KIND_WEBHOOK_MISSED)->count());
        Notification::assertNothingSent();
        // El webhook (abierto) y la resolución que vio el poll; las otras tres
        // alertas son el mismo pánico y no se guardan.
        $this->assertSame(2, RawEvent::withoutGlobalScopes()->where('team_id', $team->id)->count());
        $this->assertSystemLogged('ingestion.alert_incidents.cycle_completed', fn (array $c): bool => $c['result']['ingested'] === 1
            && $c['result']['already_ingested'] === 3);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_one_open_press_fired_by_several_panic_alerts_adds_nothing_to_the_webhook_incident(): void
    {
        $integration = $this->makeIntegration();
        $team = $integration->team;
        $open = $this->incident(happenedAt: '2026-10-02T11:57:18Z');

        $this->webhook($team, $open);

        $this->fakeSamsara([
            $open,
            $this->seenByAnotherAlert($open, 'cfg-client-a'),
            $this->seenByAnotherAlert($open, 'cfg-client-b'),
        ]);
        $this->poll($integration);

        $this->assertSame(1, RawEvent::withoutGlobalScopes()->where('team_id', $team->id)->count());
        $this->assertSame(1, $this->panicIncidents($team));
        $this->assertSystemLogged('ingestion.alert_incidents.cycle_completed', fn (array $c): bool => $c['result']['ingested'] === 0
            && $c['result']['already_ingested'] === 3);
        $this->assertSystemLogged('ingestion.alert_incidents.skipped', fn (array $c): bool => $c['reason'] === 'already_ingested'
            && $c['calc']['identity_scope'] === 'event'
            && $c['calc']['first_source'] === 'webhook');
        $this->assertSystemNotLogged('ingestion.alert_incidents.webhook_missed');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_one_press_fired_by_several_panic_alerts_without_webhook_opens_one_incident_and_alerts_once(): void
    {
        Notification::fake();
        User::factory()->create(['global_role' => 'super_admin']);
        $integration = $this->makeIntegration();
        $team = $integration->team;
        $open = $this->incident();

        $this->fakeSamsara([
            $open,
            $this->seenByAnotherAlert($open, 'cfg-client-a'),
            $this->seenByAnotherAlert($open, 'cfg-client-b'),
        ]);
        $this->poll($integration);

        $this->assertSame(1, RawEvent::withoutGlobalScopes()->where('team_id', $team->id)->count());
        $this->assertSame(1, $this->panicIncidents($team), 'un pánico, un incidente: tres alertas no abren tres escaleras');
        $this->assertSame(1, PipelineFailureAlert::withoutGlobalScopes()->where('kind', PipelineFailureAlert::KIND_WEBHOOK_MISSED)->count());
        $this->assertSystemLogged('ingestion.alert_incidents.webhook_missed', fn (array $c): bool => $c['reason'] === 'webhook_not_delivered');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_webhook_of_another_alert_for_the_same_press_is_a_duplicate_of_the_poll(): void
    {
        $integration = $this->makeIntegration();
        $team = $integration->team;
        $open = $this->incident(happenedAt: '2026-10-02T11:59:30Z');

        $this->fakeSamsara([$this->seenByAnotherAlert($open, 'cfg-client-a')]);
        $this->poll($integration);

        $this->webhook($team, $open, 'wh-sam-alert');

        $webhookRaw = RawEvent::withoutGlobalScopes()->where('team_id', $team->id)->where('external_event_id', 'wh-sam-alert')->sole();
        $this->assertSame(RawEventStatus::DuplicateDetected, $webhookRaw->fresh()->status);
        $this->assertSame(1, $this->panicIncidents($team));
    }

    public function test_two_units_pressing_at_the_same_instant_are_two_panics(): void
    {
        $integration = $this->makeIntegration();
        $team = $integration->team;
        $first = $this->incident();
        $otherUnit = $this->seenByAnotherAlert($first, 'cfg-client-a');
        $otherUnit['conditions'][0]['details']['panicButton']['vehicle']['id'] = '281474990000001';

        $this->fakeSamsara([$first, $otherUnit]);
        $this->poll($integration);

        $this->assertSame(2, RawEvent::withoutGlobalScopes()->where('team_id', $team->id)->count());
    }

    public function test_a_redelivered_webhook_with_a_new_event_id_is_still_a_duplicate(): void
    {
        $integration = $this->makeIntegration();
        $team = $integration->team;
        $incident = $this->incident();

        $this->webhook($team, $incident, 'wh-a');
        $this->webhook($team, $incident, 'wh-b');

        $this->assertSame(1, $this->panicIncidents($team));
        $this->assertSame(RawEventStatus::DuplicateDetected, RawEvent::withoutGlobalScopes()->where('external_event_id', 'wh-b')->sole()->status);
    }

    public function test_an_alert_incident_that_is_not_an_emergency_is_ignored(): void
    {
        $integration = $this->makeIntegration();
        // "Camera Obstructed" no tiene triggerTypeId público: se reconoce por texto.
        $this->fakeSamsara([$this->incident(description: 'Camera Obstructed', triggerId: null)]);

        $this->poll($integration);

        $this->assertSame(0, RawEvent::withoutGlobalScopes()->count());
        $this->assertSystemLogged('ingestion.alert_incidents.skipped', fn (array $c): bool => $c['reason'] === 'not_emergency'
            && $c['calc']['event_type_code'] === 'camera_obstructed');
    }

    public function test_an_unmapped_alert_from_a_panic_configuration_follows_the_webhook_path_and_is_escalated(): void
    {
        Notification::fake();
        User::factory()->create(['global_role' => 'super_admin']);
        $integration = $this->makeIntegration();
        // Sin triggerId no se puede leer qué disparó la alerta: podría ser un
        // pánico, así que se ingiere y se escala (con 1034 ya sería pánico,
        // diga lo que diga el texto).
        $this->fakeSamsara([$this->incident(description: 'Botón de pánico', triggerId: null)]);

        $this->poll($integration);

        $raw = RawEvent::withoutGlobalScopes()->sole();
        $this->assertSame(1, PipelineFailureAlert::withoutGlobalScopes()->where('kind', PipelineFailureAlert::KIND_UNMAPPED_ALERT)->where('raw_event_id', $raw->id)->count());
    }

    public function test_pagination_keeps_the_start_time_and_configuration_ids_constant(): void
    {
        $integration = $this->makeIntegration();
        $first = $this->incident();
        $second = $this->incident(['configurationId' => 'cfg-panic'], happenedAt: '2026-10-02T11:56:00Z');

        Http::fake([
            'api.samsara.com/alerts/configurations*' => Http::response($this->configurationsResponse()),
            'api.samsara.com/alerts/incidents/stream*' => Http::sequence()
                ->push(['data' => [$first], 'pagination' => ['endCursor' => 'c1', 'hasNextPage' => true]])
                ->push(['data' => [$second], 'pagination' => ['endCursor' => 'c2', 'hasNextPage' => false]]),
        ]);

        $this->poll($integration);

        /** @var list<array<string, mixed>> $queries */
        $queries = [];
        Http::assertSent(function (Request $request) use (&$queries): bool {
            if (str_contains($request->url(), '/alerts/incidents/stream')) {
                $queries[] = $this->parseQuery($request->url());
            }

            return true;
        });

        $this->assertCount(2, $queries);
        $this->assertSame($queries[0]['startTime'], $queries[1]['startTime'], 'Samsara exige el mismo startTime en cada página');
        $this->assertSame('2026-10-02T11:50:00+00:00', $queries[0]['startTime']);
        $this->assertSame(['cfg-panic'], $queries[0]['configurationIds']);
        $this->assertSame(['cfg-panic'], $queries[1]['configurationIds']);
        $this->assertArrayNotHasKey('after', $queries[0]);
        $this->assertSame('c1', $queries[1]['after']);

        $this->assertSame(2, RawEvent::withoutGlobalScopes()->count(), 'dos incidentes distintos, uno por página');
    }

    public function test_the_cursor_only_advances_after_the_page_is_persisted(): void
    {
        $integration = $this->makeIntegration();

        Http::fake([
            'api.samsara.com/alerts/configurations*' => Http::response($this->configurationsResponse()),
            'api.samsara.com/alerts/incidents/stream*' => Http::sequence()
                ->push(['data' => [$this->incident()], 'pagination' => ['endCursor' => 'c1', 'hasNextPage' => true]])
                ->push(['message' => 'boom', 'requestId' => 'r1'], 500)
                // Reintento: reanuda desde c1 con el startTime fijado.
                ->push(['data' => [], 'pagination' => ['endCursor' => 'c2', 'hasNextPage' => false]]),
        ]);

        try {
            $this->poll($integration);
            $this->fail('un 500 del proveedor debe propagarse para reintentar');
        } catch (\RuntimeException) {
            // esperado
        }

        $state = $integration->fresh()->sync_state_json['alert_incidents'];
        $this->assertSame('c1', $state['cursor'], 'la página 1 se guardó: su cursor queda persistido');
        $this->assertSame('2026-10-02T11:50:00+00:00', $state['start_time']);
        $this->assertSame(1, RawEvent::withoutGlobalScopes()->count());
        $this->assertStringStartsWith(PollAlertIncidentsJob::ERROR_PREFIX, (string) $integration->fresh()->last_error_message);

        // La reanudación usa el cursor y el startTime fijado.
        $this->poll($integration->fresh());

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/alerts/incidents/stream')
            && ($this->parseQuery($request->url())['after'] ?? null) === 'c1'
            && $this->parseQuery($request->url())['startTime'] === '2026-10-02T11:50:00+00:00');
        $this->assertNull($integration->fresh()->last_error_message);
    }

    public function test_a_failed_first_page_leaves_the_state_untouched(): void
    {
        $integration = $this->makeIntegration();

        Http::fake([
            'api.samsara.com/alerts/configurations*' => Http::response($this->configurationsResponse()),
            'api.samsara.com/alerts/incidents/stream*' => Http::response(['message' => 'boom', 'requestId' => 'r1'], 503),
        ]);

        try {
            $this->poll($integration);
        } catch (\RuntimeException) {
            // esperado
        }

        $state = $integration->fresh()->sync_state_json['alert_incidents'] ?? [];
        $this->assertNull($state['cursor'] ?? null);
        $this->assertNull($state['last_polled_at'] ?? null);
    }

    public function test_a_rejected_cursor_restarts_the_sweep_from_a_fresh_window(): void
    {
        $integration = $this->makeIntegration(attributes: ['sync_state_json' => ['alert_incidents' => [
            'cursor' => 'stale',
            'start_time' => '2026-10-02T09:00:00+00:00',
            'configuration_ids' => ['cfg-panic'],
            'configurations_refreshed_at' => '2026-10-02T11:59:00+00:00',
        ]]]);

        Http::fake([
            'api.samsara.com/alerts/incidents/stream*' => function (Request $request) {
                return isset($this->parseQuery($request->url())['after'])
                    ? Http::response(['message' => 'Invalid cursor', 'requestId' => 'r2'], 400)
                    : Http::response(['data' => [], 'pagination' => ['endCursor' => 'c9', 'hasNextPage' => false]]);
            },
        ]);

        $this->poll($integration);

        $this->assertSystemLogged('ingestion.alert_incidents.cursor_rejected', fn (array $c): bool => $c['reason'] === 'provider_rejected_cursor');
        $state = $integration->fresh()->sync_state_json['alert_incidents'];
        $this->assertNull($state['cursor']);
        $this->assertNotNull($state['last_polled_at']);
    }

    public function test_rate_limit_releases_the_job_without_touching_the_cursor(): void
    {
        $integration = $this->makeIntegration();

        Http::fake([
            'api.samsara.com/alerts/configurations*' => Http::response($this->configurationsResponse()),
            'api.samsara.com/alerts/incidents/stream*' => Http::response(['message' => 'Exceeded rate limit.', 'requestId' => 'r3'], 429, ['Retry-After' => '7']),
        ]);

        $this->poll($integration);

        $this->assertSystemLogged('ingestion.alert_incidents.rate_limited', fn (array $c): bool => $c['calc']['released_for_seconds'] === 7);
        $this->assertNull($integration->fresh()->sync_state_json['alert_incidents']['last_polled_at'] ?? null);
    }

    public function test_without_a_panic_configuration_the_stream_is_not_queried(): void
    {
        $integration = $this->makeIntegration();

        Http::fake([
            'api.samsara.com/alerts/configurations*' => Http::response([
                'data' => [['id' => 'cfg-speed', 'name' => 'x', 'isEnabled' => true, 'createdAtTime' => '2026-01-01T00:00:00Z', 'lastModifiedAtTime' => '2026-01-01T00:00:00Z', 'actions' => [], 'scope' => [], 'triggers' => [['triggerTypeId' => 1000]]]],
                'pagination' => ['endCursor' => '', 'hasNextPage' => false],
            ]),
            'api.samsara.com/alerts/incidents/stream*' => Http::response(['data' => [], 'pagination' => ['endCursor' => '', 'hasNextPage' => false]]),
        ]);

        $this->poll($integration);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/alerts/incidents/stream'));
        $this->assertSystemLogged('ingestion.alert_incidents.skipped', fn (array $c): bool => $c['reason'] === 'no_panic_configuration');
    }

    public function test_panic_configurations_are_cached_between_runs(): void
    {
        $integration = $this->makeIntegration();
        $this->fakeSamsara([]);

        $this->poll($integration);
        $this->poll($integration->fresh());

        Http::assertSentCount(3); // 1 configuraciones + 2 streams
    }

    public function test_a_failed_configuration_refresh_keeps_polling_with_the_cached_panic_alerts(): void
    {
        $integration = $this->makeIntegration(attributes: ['sync_state_json' => ['alert_incidents' => [
            'configuration_ids' => ['cfg-panic'],
            'configurations_refreshed_at' => '2026-10-01T00:00:00+00:00',
        ]]]);

        Http::fake([
            'api.samsara.com/alerts/configurations*' => Http::response(['message' => 'Invalid token.', 'requestId' => 'r4'], 401),
            'api.samsara.com/alerts/incidents/stream*' => Http::response(['data' => [$this->incident()], 'pagination' => ['endCursor' => 'c1', 'hasNextPage' => false]]),
        ]);

        $this->poll($integration);

        $this->assertSystemLogged('ingestion.alert_incidents.configurations_refreshed', fn (array $c): bool => $c['reason'] === 'refresh_failed_using_cache'
            && $c['calc']['cached_count'] === 1);
        $this->assertSame(1, RawEvent::withoutGlobalScopes()->count());
        $this->assertNoSensitiveDataLogged();
    }

    public function test_fan_out_only_polls_samsara_integrations_with_monitored_units(): void
    {
        Queue::fake();

        $monitored = $this->makeIntegration();
        Asset::factory()->create(['team_id' => $monitored->team_id]);

        $unmonitored = $this->makeIntegration();
        Asset::factory()->pendingMonitoring()->create(['team_id' => $unmonitored->team_id]);

        $optedOut = $this->makeIntegration(attributes: ['config_json' => ['sync' => ['poll_alert_incidents' => false]]]);
        Asset::factory()->create(['team_id' => $optedOut->team_id]);

        $inactive = $this->makeIntegration(attributes: ['status' => 'inactive']);
        Asset::factory()->create(['team_id' => $inactive->team_id]);

        // Tenant con unidades pero sin integración: nada que consultar.
        Asset::factory()->create(['team_id' => User::factory()->create()->currentTeam->id]);

        app()->call([new PollSamsaraAlertIncidentsJob, 'handle']);

        Queue::assertPushed(PollAlertIncidentsJob::class, 1);
        Queue::assertPushed(PollAlertIncidentsJob::class, fn (PollAlertIncidentsJob $job): bool => $job->integration->is($monitored));
        $this->assertSystemLogged('ingestion.alert_incidents.dispatched', fn (array $c): bool => $c['result']['dispatched_count'] === 1
            && $c['result']['no_monitored_units_count'] === 1
            && $c['result']['disabled_count'] === 1);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_fan_out_does_nothing_when_disabled(): void
    {
        Queue::fake();
        config(['pipeline.alert_incidents_poll.enabled' => false]);

        $integration = $this->makeIntegration();
        Asset::factory()->create(['team_id' => $integration->team_id]);

        app()->call([new PollSamsaraAlertIncidentsJob, 'handle']);

        Queue::assertNotPushed(PollAlertIncidentsJob::class);
        $this->assertSystemLogged('ingestion.alert_incidents.dispatched', fn (array $c): bool => $c['reason'] === 'disabled');
    }

    public function test_polling_tenant_b_never_touches_tenant_a(): void
    {
        $integrationA = $this->makeIntegration();
        $teamA = $integrationA->team;
        $this->webhook($teamA, $this->incident(), 'wh-a');
        $this->assertSame(1, $this->panicIncidents($teamA));

        $integrationB = $this->makeIntegration();
        $teamB = $integrationB->team;

        // B ve el MISMO incidente de Samsara (mismo vehículo e incidentUrl):
        // la dedup de A no debe tragarse el de B ni el de B escribir en A.
        $this->fakeSamsara([$this->incident()]);

        $this->assertNoTenantLeak($teamB, fn () => $this->poll($integrationB));

        $this->assertSame(1, $this->panicIncidents($teamA));
        $this->assertSame(1, $this->panicIncidents($teamB));
        $this->assertSame(1, RawEvent::withoutGlobalScopes()->where('team_id', $teamB->id)->count());
    }

    public function test_an_incident_without_identity_is_still_ingested_once(): void
    {
        $integration = $this->makeIntegration();
        $this->fakeSamsara([$this->incident(['incidentUrl' => '', 'configurationId' => '', 'happenedAtTime' => ''])]);

        $this->poll($integration);
        $this->poll($integration->fresh());

        $this->assertSame(1, RawEvent::withoutGlobalScopes()->count(), 'sin identidad estable se ingiere igual (perder un pánico es peor), pero una sola vez');
        $this->assertSystemLogged('ingestion.alert_incidents.ingested', fn (array $c): bool => $c['calc']['identity'] === false
            && $c['calc']['identity_scope'] === null);
        $this->assertSystemLogged('ingestion.alert_incidents.skipped', fn (array $c): bool => $c['reason'] === 'already_ingested');
    }

    public function test_ingest_action_is_resolved_from_the_container(): void
    {
        $this->assertInstanceOf(IngestAlertIncident::class, app(IngestAlertIncident::class));
        $this->assertInstanceOf(ProviderAdapter::class, app(ProviderAdapter::class));
    }

    /**
     * Query string con parámetros repetidos (`configurationIds=a&configurationIds=b`).
     *
     * @return array<string, mixed>
     */
    private function parseQuery(string $url): array
    {
        $query = (string) parse_url($url, PHP_URL_QUERY);
        $out = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($key);
            $value = urldecode($value);

            if ($key === 'configurationIds') {
                $out[$key][] = $value;
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    public function test_the_alert_incidents_poll_does_not_clobber_the_safety_events_state(): void
    {
        $integration = $this->makeIntegration();
        $this->fakeSamsara([]);

        // Otro poller escribió su sub-clave después de que este job cargara la integración.
        $stale = $integration->fresh();
        TenantIntegration::withoutGlobalScopes()->whereKey($integration->id)->update([
            'sync_state_json' => json_encode(['safety_events' => ['cursor' => 'safety-c', 'start_time' => 'x', 'last_polled_at' => 'y']]),
        ]);

        $this->poll($stale);

        $state = $integration->fresh()->sync_state_json;
        $this->assertSame('safety-c', $state['safety_events']['cursor']);
        $this->assertArrayHasKey('alert_incidents', $state);
    }

    public function test_the_safety_events_poll_does_not_clobber_the_alert_incidents_state(): void
    {
        $integration = $this->makeIntegration();
        Http::fake([
            'api.samsara.com/safety-events/stream*' => Http::response(['data' => [], 'pagination' => ['endCursor' => 'safety-c2', 'hasNextPage' => false]]),
        ]);

        $stale = $integration->fresh();
        TenantIntegration::withoutGlobalScopes()->whereKey($integration->id)->update([
            'sync_state_json' => json_encode(['alert_incidents' => ['configuration_ids' => ['cfg-panic'], 'cursor' => 'alert-c']]),
        ]);

        app()->call([new PollSafetyEventsJob($stale), 'handle']);

        $state = $integration->fresh()->sync_state_json;
        $this->assertSame('alert-c', $state['alert_incidents']['cursor']);
        $this->assertSame('safety-c2', $state['safety_events']['cursor']);
    }
}
