<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Decisions\Enums\DecisionOutcomeCode;
use App\Domains\Decisions\Enums\DecisionPriority;
use App\Domains\Decisions\Events\DecisionMade;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Decisions\Models\DecisionOutcome;
use App\Domains\Incidents\Actions\ApplyReevaluationToIncident;
use App\Domains\Incidents\Actions\CreateIncidentFromEvent;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Jobs\CreateIncidentJob;
use App\Domains\Incidents\Listeners\ApplyReevaluationOnDecisionMade;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Cada versión de evaluación produce una Decision nueva para el mismo evento:
 * el incidente existente se actualiza, nunca se duplica.
 */
class ApplyReevaluationToIncidentTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IncidentsSeeder::class);
        $this->team = Team::factory()->create();
    }

    private function decisionFor(
        NormalizedEvent $event,
        int $version,
        EventClassification $classification,
        float $confidence,
        DecisionOutcomeCode $outcome,
        DecisionPriority $priority,
    ): Decision {
        $evaluation = AIEventEvaluation::factory()->create([
            'team_id' => $event->team_id,
            'normalized_event_id' => $event->id,
            'evaluation_version' => $version,
            'classification' => $classification,
            'confidence_score' => $confidence,
        ]);

        $outcomeRow = DecisionOutcome::firstOrCreate(
            ['code' => $outcome->value],
            ['name' => $outcome->name, 'is_terminal' => $outcome->isTerminal()],
        );

        return Decision::factory()->create([
            'team_id' => $event->team_id,
            'normalized_event_id' => $event->id,
            'ai_evaluation_id' => $evaluation->id,
            'decision_code' => $outcome->value,
            'outcome_id' => $outcomeRow->id,
            'priority_level' => $priority,
        ]);
    }

    /**
     * Simula el camino real de una decisión: listener síncrono + (si el
     * outcome crea incidente) el CreateIncidentJob encolado.
     */
    private function runDecision(Decision $decision): void
    {
        app(ApplyReevaluationOnDecisionMade::class)->handle(new DecisionMade($decision));

        if (in_array($decision->decision_code, ['INCIDENT', 'ESCALATE'], true)) {
            (new CreateIncidentJob((int) $decision->normalized_event_id, [
                'decision_id' => $decision->id,
                'priority_code' => $decision->priority_level?->value,
            ]))->handle(app(CreateIncidentFromEvent::class));
        }
    }

    /**
     * @return Collection<int, Incident>
     */
    private function incidentsFor(NormalizedEvent $event)
    {
        return Incident::withoutGlobalScopes()
            ->where('team_id', $event->team_id)
            ->where('related_event_id', $event->id)
            ->get();
    }

    private function reevaluationEntries(Incident $incident)
    {
        return IncidentTimeline::query()
            ->where('incident_id', $incident->id)
            ->where('entry_type', TimelineEntryType::AiReevaluated)
            ->orderBy('id')
            ->get();
    }

    public function test_v2_escalate_raises_priority_on_the_same_incident(): void
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->team->id, 'asset_id' => null, 'driver_id' => null]);

        $v1 = $this->decisionFor($event, 1, EventClassification::RealEvent, 0.7, DecisionOutcomeCode::Incident, DecisionPriority::Normal);
        $this->runDecision($v1);

        $incidents = $this->incidentsFor($event);
        $this->assertCount(1, $incidents);
        $this->assertSame('medium', $incidents->first()->fresh('priority')->priority->code);

        $v2 = $this->decisionFor($event, 2, EventClassification::RealEvent, 0.93, DecisionOutcomeCode::Escalate, DecisionPriority::Critical);
        $this->runDecision($v2);

        $incidents = $this->incidentsFor($event);
        $this->assertCount(1, $incidents, 'La reevaluación no debe abrir otro incidente.');

        $incident = $incidents->first()->fresh('priority');
        $this->assertSame('critical', $incident->priority->code);
        $this->assertSame($v2->id, (int) $incident->related_decision_id);

        $entries = $this->reevaluationEntries($incident);
        $this->assertCount(1, $entries, 'Listener + job no deben duplicar la entrada.');
        $this->assertSame('Reevaluación v2: evento real (93%)', $entries->first()->title);

        $this->assertTrue(IncidentTimeline::query()
            ->where('incident_id', $incident->id)
            ->where('entry_type', TimelineEntryType::PriorityChanged)
            ->exists());

        $c = $this->assertSystemLogged('incidents.reevaluation.applied', fn (array $c) => $c['outcome'] === 'ok'
            && $c['input']['decision_id'] === $v2->id);
        $this->assertSame($incident->id, $c['input']['incident_id']);
        $this->assertSame('ESCALATE', $c['input']['decision_code']);
        $this->assertTrue($c['result']['priority_raised']);
        // La subida se rehace con los términos registrados.
        $this->assertSame(
            ! $c['calc']['is_terminal'] && $c['calc']['mapped_level'] !== null && $c['calc']['mapped_level'] > ($c['calc']['previous_level'] ?? 0),
            $c['result']['priority_raised'],
        );
        $this->assertSame('medium', $c['calc']['previous_priority_code']);
        $this->assertSame('critical', $c['calc']['mapped_priority_code']);
        $this->assertSame($incident->priority->level, $c['calc']['mapped_level']);
        $this->assertFalse($c['calc']['priority_alias_used']);
        $this->assertSame('critical', $c['calc']['decision_priority_code']);
        $this->assertArrayNotHasKey('decision_priority_level', $c['calc']);
        $this->assertSame($v1->id, $c['calc']['previous_decision_id']);
        $this->assertTrue($c['calc']['is_root_event']);
        $this->assertTrue($c['result']['related_decision_moved']);
        $this->assertFalse($c['result']['false_positive_notice']);
        $this->assertSame(2, $c['result']['evaluation_version']);
        $this->assertSame('real_event', $c['result']['classification']);

        // El job que llega después con la misma decisión no la reaplica.
        $this->assertSystemLogged('incidents.reevaluation.applied', fn (array $c) => ($c['reason'] ?? null) === 'decision_not_newer'
            && $c['calc']['current_decision_id'] === $v2->id);

        $json = json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString('Reevaluación v2', $json);
        $this->assertStringNotContainsString((string) json_encode($incident->title), $json);
        if ($v2->decision_reason) {
            $this->assertStringNotContainsString((string) json_encode($v2->decision_reason), $json);
        }
        $this->assertNoSensitiveDataLogged();
    }

    public function test_v2_on_event_without_asset_or_driver_does_not_duplicate(): void
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->team->id, 'asset_id' => null, 'driver_id' => null]);

        $v1 = $this->decisionFor($event, 1, EventClassification::RealEvent, 0.8, DecisionOutcomeCode::Incident, DecisionPriority::High);
        (new CreateIncidentJob($event->id, ['decision_id' => $v1->id, 'priority_code' => 'high']))
            ->handle(app(CreateIncidentFromEvent::class));

        // Sólo el job (sin listener): el camino que antes duplicaba.
        $v2 = $this->decisionFor($event, 2, EventClassification::RealEvent, 0.6, DecisionOutcomeCode::Incident, DecisionPriority::Low);
        (new CreateIncidentJob($event->id, ['decision_id' => $v2->id, 'priority_code' => 'low']))
            ->handle(app(CreateIncidentFromEvent::class));

        $incidents = $this->incidentsFor($event);
        $this->assertCount(1, $incidents);

        $incident = $incidents->first()->fresh('priority');
        $this->assertSame('high', $incident->priority->code, 'La prioridad nunca baja sola.');
        $this->assertSame($v2->id, (int) $incident->related_decision_id);
        $this->assertSame('Reevaluación v2: evento real (60%)', $this->reevaluationEntries($incident)->first()->title);
    }

    public function test_v2_false_positive_adds_notice_and_keeps_incident_open(): void
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->team->id]);

        $v1 = $this->decisionFor($event, 1, EventClassification::RealEvent, 0.75, DecisionOutcomeCode::Incident, DecisionPriority::High);
        $this->runDecision($v1);

        $v2 = $this->decisionFor($event, 2, EventClassification::FalsePositive, 0.88, DecisionOutcomeCode::LogOnly, DecisionPriority::Low);
        $this->runDecision($v2);

        $incident = $this->incidentsFor($event)->sole()->fresh(['status', 'priority']);

        $this->assertSame(IncidentStatusCode::Open->value, $incident->status->code);
        $this->assertNull($incident->false_positive_at);
        $this->assertSame('high', $incident->priority->code);

        $titles = $this->reevaluationEntries($incident)->pluck('title')->all();
        $this->assertSame([
            'Reevaluación v2: falso positivo (88%)',
            'La IA sugiere falso positivo — requiere confirmación del operador',
        ], $titles);

        $c = $this->assertSystemLogged('incidents.reevaluation.applied', fn (array $c) => $c['outcome'] === 'ok'
            && $c['input']['decision_id'] === $v2->id);
        $this->assertTrue($c['result']['false_positive_notice']);
        $this->assertFalse($c['result']['priority_raised']);
        $this->assertSame(
            ! $c['calc']['is_terminal'] && $c['calc']['mapped_level'] !== null && $c['calc']['mapped_level'] > ($c['calc']['previous_level'] ?? 0),
            $c['result']['priority_raised'],
        );
        $this->assertSame('false_positive', $c['result']['classification']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_applying_an_older_decision_is_a_no_op(): void
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->team->id]);

        $v1 = $this->decisionFor($event, 1, EventClassification::RealEvent, 0.7, DecisionOutcomeCode::Incident, DecisionPriority::Normal);
        $v2 = $this->decisionFor($event, 2, EventClassification::RealEvent, 0.9, DecisionOutcomeCode::Incident, DecisionPriority::High);

        (new CreateIncidentJob($event->id, ['decision_id' => $v2->id, 'priority_code' => 'high']))
            ->handle(app(CreateIncidentFromEvent::class));

        // v1 llega tarde (cola desordenada): no pisa a v2.
        $this->runDecision($v1);

        $incident = $this->incidentsFor($event)->sole();
        $this->assertSame($v2->id, (int) $incident->related_decision_id);
        $this->assertCount(0, $this->reevaluationEntries($incident));

        $this->assertSystemLogged('incidents.reevaluation.applied', fn (array $c) => $c['outcome'] === 'skipped'
            && $c['reason'] === 'decision_not_newer'
            && $c['input']['decision_id'] === $v1->id
            && $c['input']['incident_id'] === $incident->id
            && $c['calc']['current_decision_id'] === $v2->id
            && $c['calc']['is_root_event'] === true);
        $this->assertCount(0, array_filter(
            $this->systemLogEntries('incidents.reevaluation.applied'),
            fn (array $e) => $e['context']['outcome'] === 'ok',
        ));
    }

    public function test_reevaluation_never_touches_another_tenants_incident(): void
    {
        $victim = Team::factory()->create();
        $event = NormalizedEvent::factory()->create(['team_id' => $this->team->id]);

        // Dato corrupto: un incidente de otro tenant apuntando a este evento.
        $foreign = Incident::factory()->open()->create([
            'team_id' => $victim->id,
            'related_event_id' => $event->id,
        ]);

        $decision = $this->decisionFor($event, 2, EventClassification::RealEvent, 0.9, DecisionOutcomeCode::Escalate, DecisionPriority::Critical);

        $this->assertNoTenantLeak($this->team, fn () => $this->runDecision($decision));

        $this->assertNull($foreign->fresh()->related_decision_id);
        $this->assertCount(0, $this->reevaluationEntries($foreign));
        $this->assertCount(1, $this->incidentsFor($event), 'El tenant dueño del evento obtiene su propio incidente.');

        // Aplicada directamente sobre el incidente ajeno: se rechaza sin
        // registrar el id del otro tenant.
        $this->assertFalse(app(ApplyReevaluationToIncident::class)->execute($foreign, $decision));
        $this->assertNull($foreign->fresh()->related_decision_id);

        $c = $this->assertSystemLogged('incidents.reevaluation.applied', fn (array $c) => ($c['reason'] ?? null) === 'team_mismatch');
        $this->assertSame(['decision_id' => $decision->id], $c['input']);
        $this->assertFalse($c['calc']['team_matches']);
        $this->assertArrayNotHasKey('result', $c);
        $this->assertNoSensitiveDataLogged();
    }
}
