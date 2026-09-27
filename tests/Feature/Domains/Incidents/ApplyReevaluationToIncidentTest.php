<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Decisions\Enums\DecisionOutcomeCode;
use App\Domains\Decisions\Enums\DecisionPriority;
use App\Domains\Decisions\Events\DecisionMade;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Decisions\Models\DecisionOutcome;
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
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Cada versión de evaluación produce una Decision nueva para el mismo evento:
 * el incidente existente se actualiza, nunca se duplica.
 */
class ApplyReevaluationToIncidentTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

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
    }
}
