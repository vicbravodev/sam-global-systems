<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Actions\RecordOperatorVerdict;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Enums\OperatorVerdict;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Human-in-the-loop: "Confirmar" y "Descartar como falso positivo" en la
 * bandeja dejan una etiqueta del operador sobre la evaluación de IA.
 */
class OperatorVerdictTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
        $this->seed(IncidentsSeeder::class);

        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;
    }

    /**
     * @return array{0: Incident, 1: NormalizedEvent, 2: AIEventEvaluation}
     */
    private function incidentWithEvaluations(Team $team, EventClassification $latest = EventClassification::RealEvent): array
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);

        AIEventEvaluation::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
            'evaluation_version' => 1,
            'classification' => EventClassification::Unclear,
        ]);

        $latestEvaluation = AIEventEvaluation::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
            'evaluation_version' => 2,
            'classification' => $latest,
        ]);

        $incident = Incident::factory()->open()->create([
            'team_id' => $team->id,
            'related_event_id' => $event->id,
        ]);

        return [$incident, $event, $latestEvaluation];
    }

    public function test_action_labels_the_latest_evaluation_and_audits_it(): void
    {
        [, $event, $latest] = $this->incidentWithEvaluations($this->team);

        $result = app(RecordOperatorVerdict::class)->execute(
            teamId: $this->team->id,
            normalizedEventId: $event->id,
            verdict: OperatorVerdict::Confirmed,
            userId: $this->user->id,
            note: '  Verificado por radio  ',
        );

        $this->assertNotNull($result);
        $this->assertSame($latest->id, $result->id);

        $latest->refresh();
        $this->assertSame(OperatorVerdict::Confirmed, $latest->operator_verdict);
        $this->assertSame($this->user->id, (int) $latest->operator_verdict_by);
        $this->assertNotNull($latest->operator_verdict_at);
        $this->assertSame('Verificado por radio', $latest->operator_verdict_note);

        $this->assertNull(
            AIEventEvaluation::withoutGlobalScopes()->where('team_id', $this->team->id)
                ->where('evaluation_version', 1)->value('operator_verdict'),
            'Sólo la evaluación más reciente recibe la etiqueta.',
        );

        $audit = AuditLog::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->where('action', 'ai.operator_verdict.recorded')
            ->first();
        $this->assertNotNull($audit);
        $this->assertTrue($audit->metadata_json['agrees_with_ai']);
    }

    public function test_action_returns_null_without_evaluation(): void
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->team->id]);

        $this->assertNull(app(RecordOperatorVerdict::class)->execute(
            teamId: $this->team->id,
            normalizedEventId: $event->id,
            verdict: OperatorVerdict::Confirmed,
        ));
    }

    public function test_action_never_labels_another_tenants_evaluation(): void
    {
        $victim = Team::factory()->create();
        [, $foreignEvent, $foreignEvaluation] = $this->incidentWithEvaluations($victim);

        $result = $this->assertNoTenantLeak(
            $this->team,
            fn () => app(RecordOperatorVerdict::class)->execute(
                teamId: $this->team->id,
                normalizedEventId: $foreignEvent->id,
                verdict: OperatorVerdict::FalsePositive,
            ),
        );

        $this->assertNull($result);
        $this->assertNull($foreignEvaluation->fresh()->operator_verdict);
    }

    public function test_confirm_endpoint_records_confirmed_verdict(): void
    {
        [$incident, , $latest] = $this->incidentWithEvaluations($this->team);

        $response = $this->actingAs($this->user)->postJson(
            route('incidents.ai-verdict', ['current_team' => $this->team->slug, 'incident' => $incident->id]),
            ['verdict' => 'confirmed'],
        );

        $response->assertOk()
            ->assertJsonPath('data.operator_verdict', 'confirmed')
            ->assertJsonPath('data.operator_verdict_label', 'Confirmado por operador');

        $this->assertSame(OperatorVerdict::Confirmed, $latest->fresh()->operator_verdict);
        $this->assertDatabaseHas('incident_comments', [
            'incident_id' => $incident->id,
            'visibility' => 'audit_only',
        ]);
    }

    public function test_confirm_endpoint_validates_verdict(): void
    {
        [$incident] = $this->incidentWithEvaluations($this->team);

        $this->actingAs($this->user)->postJson(
            route('incidents.ai-verdict', ['current_team' => $this->team->slug, 'incident' => $incident->id]),
            ['verdict' => 'maybe'],
        )->assertUnprocessable();
    }

    public function test_confirm_endpoint_rejects_incident_of_another_team(): void
    {
        $other = Team::factory()->create();
        [$foreignIncident, , $foreignEvaluation] = $this->incidentWithEvaluations($other);

        $this->actingAs($this->user)->postJson(
            route('incidents.ai-verdict', ['current_team' => $this->team->slug, 'incident' => $foreignIncident->id]),
            ['verdict' => 'confirmed'],
        )->assertNotFound();

        $this->assertNull($foreignEvaluation->fresh()->operator_verdict);
    }

    public function test_discard_as_false_positive_records_operator_verdict(): void
    {
        [$incident, , $latest] = $this->incidentWithEvaluations($this->team);

        $this->actingAs($this->user)->postJson(
            route('incidents.resolve', ['current_team' => $this->team->slug, 'incident' => $incident->id]),
            ['resolution_code' => 'false_positive', 'summary' => 'Botón presionado por error en base.'],
        )->assertCreated();

        $latest->refresh();
        $this->assertSame(OperatorVerdict::FalsePositive, $latest->operator_verdict);
        $this->assertSame($this->user->id, (int) $latest->operator_verdict_by);
        $this->assertSame('Botón presionado por error en base.', $latest->operator_verdict_note);
    }

    public function test_regular_resolution_does_not_record_a_verdict(): void
    {
        [$incident, , $latest] = $this->incidentWithEvaluations($this->team);

        $this->actingAs($this->user)->postJson(
            route('incidents.resolve', ['current_team' => $this->team->slug, 'incident' => $incident->id]),
            ['resolution_code' => 'handled_successfully', 'summary' => 'Atendido.'],
        )->assertCreated();

        $this->assertNull($latest->fresh()->operator_verdict);
    }

    public function test_incident_detail_page_exposes_operator_verdict(): void
    {
        [$incident, , $latest] = $this->incidentWithEvaluations($this->team);
        $latest->forceFill([
            'operator_verdict' => OperatorVerdict::FalsePositive,
            'operator_verdict_at' => now(),
        ])->save();

        $this->actingAs($this->user)
            ->get(route('incidents.show', ['current_team' => $this->team->slug, 'incident' => $incident->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('incidents/show')
                ->where('incident.aiOperatorVerdict', 'false_positive')
                ->where('incident.aiOperatorVerdictLabel', 'Falso positivo (operador)')
                ->has('incident.aiOperatorVerdictAt'));
    }

    public function test_ai_performance_endpoint_reports_verdict_agreement(): void
    {
        $makeLabelled = function (EventClassification $classification, OperatorVerdict $verdict, ?Team $team = null): void {
            AIEventEvaluation::factory()->create([
                'team_id' => ($team ?? $this->team)->id,
                'normalized_event_id' => NormalizedEvent::factory()->create(['team_id' => ($team ?? $this->team)->id])->id,
                'classification' => $classification,
                'operator_verdict' => $verdict,
                'operator_verdict_at' => now(),
            ]);
        };

        $makeLabelled(EventClassification::RealEvent, OperatorVerdict::Confirmed);        // agree
        $makeLabelled(EventClassification::FalsePositive, OperatorVerdict::FalsePositive); // agree
        $makeLabelled(EventClassification::FalsePositive, OperatorVerdict::Confirmed);     // IA falso negativo
        $makeLabelled(EventClassification::RealEvent, OperatorVerdict::FalsePositive);     // IA de más
        // Otro tenant: nunca cuenta.
        $makeLabelled(EventClassification::RealEvent, OperatorVerdict::Confirmed, Team::factory()->create());

        $this->actingAs($this->user)
            ->getJson("/api/{$this->team->slug}/analytics/ai-performance")
            ->assertOk()
            ->assertJsonPath('operator_verdicts.total', 4)
            ->assertJsonPath('operator_verdicts.confirmed', 2)
            ->assertJsonPath('operator_verdicts.false_positive', 2)
            ->assertJsonPath('operator_verdicts.agreed', 2)
            ->assertJsonPath('operator_verdicts.agreement_rate', 0.5)
            ->assertJsonPath('operator_verdicts.ai_false_positive_overruled', 1)
            ->assertJsonPath('operator_verdicts.ai_real_event_overruled', 1)
            ->assertJsonPath('operator_verdicts.window_days', 30);
    }
}
