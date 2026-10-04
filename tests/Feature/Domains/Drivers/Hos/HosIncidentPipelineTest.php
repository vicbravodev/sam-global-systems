<?php

namespace Tests\Feature\Domains\Drivers\Hos;

use App\Contracts\AI\EventEvaluationAgent;
use App\Domains\AI\Actions\EvaluateEventWithAI;
use App\Domains\AI\Data\AIEvaluationResult;
use App\Domains\AI\Data\AIInputContext;
use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Events\AIEvaluationCompleted;
use App\Domains\Assets\Models\Asset;
use App\Domains\Decisions\Actions\EvaluateDecisionRules;
use App\Domains\Decisions\Events\DecisionMade;
use App\Domains\Decisions\Models\DecisionRule;
use App\Domains\Decisions\Models\RuleSet;
use App\Domains\Drivers\Actions\RaiseHosIncident;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\HosEpisode;
use App\Domains\Incidents\Actions\CreateIncidentFromEvent;
use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Incidents\Models\IncidentType;
use App\Domains\Ingestion\Jobs\ProcessRawEventJob;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Normalization\Actions\NormalizeRawEvent;
use App\Domains\Normalization\Events\EventNormalized;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\TenantConfig\Actions\ApplyDefaultTenantConfig;
use App\Models\Team;
use App\Support\TenantContext;
use Database\Seeders\DecisionOutcomeSeeder;
use Database\Seeders\IncidentsSeeder;
use Database\Seeders\NormalizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * El incidente HOS sale por el pipeline existente: evento interno →
 * normalización (con chofer) → evaluación resuelta por regla (sin IA) →
 * regla `hos-incident` → INCIDENT → incidente `hos_compliance`.
 */
class HosIncidentPipelineTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NormalizationSeeder::class);
        $this->seed(DecisionOutcomeSeeder::class);
        $this->seed(IncidentsSeeder::class);
    }

    /**
     * @return array{0: HosEpisode, 1: Driver, 2: Asset}
     */
    private function episode(HosSituation $situation = HosSituation::BreakDue, bool $withAsset = true): array
    {
        $team = Team::factory()->create();
        $driver = Driver::factory()->create(['team_id' => $team->id]);
        $asset = Asset::factory()->create(['team_id' => $team->id]);
        $episode = HosEpisode::factory()->create([
            'team_id' => $team->id, 'driver_id' => $driver->id, 'asset_id' => $withAsset ? $asset->id : null,
            'situation' => $situation, 'opened_at' => now()->subMinutes(20), 'ladder_step' => 5,
            'snapshot_json' => ['break_remaining_s' => 1500],
        ]);

        return [$episode, $driver, $asset];
    }

    private function raise(HosEpisode $episode): array
    {
        return TenantContext::for($episode->team_id, fn () => app(RaiseHosIncident::class)->execute($episode, null, now()->toImmutable()));
    }

    public function test_raising_stores_one_internal_event_per_episode(): void
    {
        Queue::fake();
        [$episode, $driver, $asset] = $this->episode();

        $first = $this->raise($episode);
        $second = $this->raise($episode);

        $this->assertTrue($first['raised']);
        $this->assertFalse($second['raised']);
        $this->assertSame('already_raised', $second['reason']);

        $raw = RawEvent::withoutGlobalScopes()->sole();
        $this->assertSame("hos:{$episode->id}", $raw->deduplication_key);
        $this->assertSame('hos_unattended', $raw->event_type_raw);
        $this->assertSame($episode->team_id, $raw->team_id);
        $this->assertSame(['monitor' => 'hos_watchdog', 'asset_id' => $asset->id, 'driver_id' => $driver->id, 'episode_id' => $episode->id], $raw->payload_json['internal']);
        Queue::assertPushed(ProcessRawEventJob::class, 1);

        $raised = $this->assertSystemLogged('hos.incident.raised', fn (array $c) => $c['outcome'] === 'ok');
        $this->assertSame($raw->id, $raised['result']['raw_event_id']);
        $this->assertSame('already_raised', $this->assertSystemLogged('hos.incident.raised', fn (array $c) => ($c['reason'] ?? null) !== null)['reason']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_violation_raises_hos_limit_exceeded_and_an_episode_without_unit_raises_nothing(): void
    {
        Queue::fake();
        [$violation] = $this->episode(HosSituation::Violation);
        [$orphan] = $this->episode(withAsset: false);

        $this->raise($violation);
        $skipped = $this->raise($orphan);

        $this->assertSame('hos_limit_exceeded', RawEvent::withoutGlobalScopes()->sole()->event_type_raw);
        $this->assertSame('no_asset', $skipped['reason']);
    }

    public function test_the_internal_event_carries_its_driver_but_never_a_foreign_one(): void
    {
        Event::fake([EventNormalized::class]);
        [$episode, $driver, $asset] = $this->episode();
        $foreign = Driver::factory()->create();

        $mine = $this->normalize($episode->team_id, $asset->id, $driver->id);
        $this->assertSame($driver->id, $mine->driver_id);

        $other = $this->normalize($episode->team_id, $asset->id, $foreign->id);
        $this->assertNull($other->driver_id);
        $this->assertSame('cross_tenant_internal_driver', $this->assertSystemLogged('normalization.driver.rejected')['reason']);
        $this->assertNoSensitiveDataLogged();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hosEventTypes(): array
    {
        return ['infracción en los relojes' => ['hos_limit_exceeded'], 'escalera agotada' => ['hos_unattended']];
    }

    #[DataProvider('hosEventTypes')]
    public function test_hos_events_open_an_incident_without_calling_the_ai(string $code): void
    {
        Event::fake([AIEvaluationCompleted::class, DecisionMade::class]);
        $this->app->instance(EventEvaluationAgent::class, new class implements EventEvaluationAgent
        {
            public function evaluate(AIInputContext $context): AIEvaluationResult
            {
                throw new RuntimeException('La IA no debe llamarse para un evento HOS.');
            }
        });

        $team = Team::factory()->create();
        app(ApplyDefaultTenantConfig::class)->execute($team);
        $type = EventType::query()->where('code', $code)->sole();
        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id, 'event_type_id' => $type->id, 'event_category_id' => $type->category_id,
            'event_severity_id' => $type->default_severity_id, 'payload_normalized_json' => ['severity' => 'high'],
        ]);

        $decision = TenantContext::for($team->id, function () use ($event) {
            $evaluation = app(EvaluateEventWithAI::class)->execute($event);
            $this->assertSame(EvaluationMode::RulesOnly, $evaluation->evaluation_mode);
            $this->assertSame(EventClassification::RealEvent, $evaluation->classification);

            return app(EvaluateDecisionRules::class)->execute($evaluation);
        });

        $this->assertSame('INCIDENT', $decision->outcome?->code);
        $this->assertSystemLogged('ai.heuristics.evaluated', fn (array $c) => $c['result']['rule'] === 'rule_resolved_type');
    }

    public function test_a_samsara_hos_violation_still_goes_to_the_ai(): void
    {
        $this->app->instance(EventEvaluationAgent::class, new class implements EventEvaluationAgent
        {
            public function evaluate(AIInputContext $context): AIEvaluationResult
            {
                throw new RuntimeException('Agente espía: prueba que sí se intentó la IA.');
            }
        });

        $team = Team::factory()->create();
        app(ApplyDefaultTenantConfig::class)->execute($team);
        $type = EventType::query()->where('code', 'hos_violation')->sole();
        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id, 'event_type_id' => $type->id, 'event_category_id' => $type->category_id,
            'event_severity_id' => $type->default_severity_id, 'payload_normalized_json' => ['severity' => 'high'],
        ]);

        Event::fake([AIEvaluationCompleted::class, DecisionMade::class, IncidentCreated::class]);
        $evaluation = TenantContext::for($team->id, fn () => app(EvaluateEventWithAI::class)->execute($event));

        // Camino previo: ninguna regla lo resuelve; se intentó el agente (cae a agent_error_fallback).
        $this->assertSame('agent_error_fallback', $evaluation->signals_json['reasoning_steps'][0]);
        $this->assertSystemLogged('ai.heuristics.evaluated', fn (array $c) => $c['result']['rule'] === null);
        $this->assertNotContains('hos_violation', config('ai.rule_resolved_event_types'));
        $this->assertNotContains('hos_violation', ApplyDefaultTenantConfig::HOS_EVENT_TYPES);

        // Sin alias nuevo: el incidente de un hos_violation sigue en la cubeta de cumplimiento.
        $incident = TenantContext::for($team->id, fn () => app(CreateIncidentFromEvent::class)->execute($event));
        $this->assertSame('compliance_violation', $incident->type?->code);
    }

    public function test_both_hos_events_of_a_driver_share_one_hos_compliance_incident(): void
    {
        Event::fake([IncidentCreated::class]);
        [$episode, $driver, $asset] = $this->episode();
        $create = app(CreateIncidentFromEvent::class);

        $incidents = TenantContext::for($episode->team_id, function () use ($create, $episode, $driver, $asset) {
            $make = fn (string $code) => NormalizedEvent::factory()->create([
                'team_id' => $episode->team_id, 'asset_id' => $asset->id, 'driver_id' => $driver->id,
                'event_type_id' => EventType::query()->where('code', $code)->value('id'),
                'event_category_id' => EventType::query()->where('code', $code)->value('category_id'),
            ]);

            return [$create->execute($make('hos_unattended')), $create->execute($make('hos_limit_exceeded'))];
        });

        $this->assertSame('hos_compliance', $incidents[0]->type?->code);
        $this->assertSame($driver->id, $incidents[0]->driver_id);
        // Mismo chofer y tipo dentro de la ventana: el segundo se vincula al primero.
        $this->assertSame($incidents[0]->id, $incidents[1]->id);
    }

    public function test_the_migration_backfills_existing_tenants_once(): void
    {
        $team = Team::factory()->create();
        $ruleSet = RuleSet::factory()->create(['team_id' => $team->id, 'code' => 'custom', 'is_default' => true, 'is_active' => true]);
        $inactive = RuleSet::factory()->create(['team_id' => Team::factory()->create()->id, 'is_default' => true, 'is_active' => false]);
        EventType::query()->whereIn('code', ['hos_limit_exceeded', 'hos_unattended'])->delete();
        IncidentType::query()->where('code', 'hos_compliance')->delete();
        $samsaraViolation = EventType::query()->where('code', 'hos_violation')->sole()->only(['name', 'category_id', 'default_severity_id', 'is_active']);

        $migration = require database_path('migrations/2026_10_11_100200_seed_hos_incident_catalog.php');
        $migration->up();
        $migration->up();

        $this->assertSame('compliance', EventType::query()->where('code', 'hos_limit_exceeded')->sole()->category->code);
        $this->assertSame('compliance', EventType::query()->where('code', 'hos_unattended')->sole()->category->code);
        // El hos_violation de Samsara queda idéntico.
        $this->assertSame($samsaraViolation, EventType::query()->where('code', 'hos_violation')->sole()->only(['name', 'category_id', 'default_severity_id', 'is_active']));
        $this->assertSame(
            IncidentPriority::query()->where('code', 'high')->value('id'),
            IncidentType::query()->where('code', 'hos_compliance')->sole()->default_priority_id,
        );

        $rule = DecisionRule::withoutGlobalScopes()->where('ruleset_id', $ruleSet->id)->sole();
        $this->assertSame('hos-incident', $rule->code);
        $this->assertSame($team->id, $rule->team_id);
        $this->assertTrue($rule->stop_processing);
        $this->assertSame(
            ['all' => [['field' => 'event_type_code', 'operator' => 'in', 'value' => ['hos_limit_exceeded', 'hos_unattended']]]],
            $rule->conditions_json,
        );
        $this->assertSame(0, DecisionRule::withoutGlobalScopes()->where('ruleset_id', $inactive->id)->count());
    }

    private function normalize(int $teamId, int $assetId, int $driverId): NormalizedEvent
    {
        $raw = RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $teamId, 'provider_id' => null, 'event_type_raw' => 'hos_unattended',
            'payload_json' => ['eventType' => 'hos_unattended', 'internal' => ['monitor' => 'hos_watchdog', 'asset_id' => $assetId, 'driver_id' => $driverId, 'episode_id' => 1]],
        ]);

        $event = TenantContext::for($teamId, fn () => app(NormalizeRawEvent::class)->execute($raw));
        $this->assertNotNull($event);

        return $event;
    }
}
