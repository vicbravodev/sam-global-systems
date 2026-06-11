<?php

namespace Tests\Feature\Domains\Decisions;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Ingestion\Models\RawEvent;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rule tester (fase 1.4): evalúa condiciones borrador contra el último
 * evento/evaluación real del tenant, con veredicto hoja por hoja.
 */
class RuleTestControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);

        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;
    }

    public function test_decision_conditions_match_against_latest_evaluation(): void
    {
        AIEventEvaluation::factory()->create([
            'team_id' => $this->team->id,
            'risk_score' => 0.9,
        ]);

        $response = $this->actingAs($this->user)->postJson(
            route('rules.test.decision', ['current_team' => $this->team->slug]),
            [
                'conditions_json' => [
                    'all' => [
                        ['field' => 'risk_score', 'operator' => 'gte', 'value' => 0.5],
                        ['field' => 'classification', 'operator' => 'eq', 'value' => 'real_event'],
                    ],
                ],
            ],
        );

        $response->assertOk();
        $response->assertJsonPath('result', 'match');
        $response->assertJsonCount(2, 'checks');
        $response->assertJsonPath('checks.0.passed', true);
        $response->assertJsonPath('checks.0.actual', 0.9);
    }

    public function test_decision_conditions_report_no_match_with_failing_check(): void
    {
        AIEventEvaluation::factory()->create([
            'team_id' => $this->team->id,
            'risk_score' => 0.2,
        ]);

        $response = $this->actingAs($this->user)->postJson(
            route('rules.test.decision', ['current_team' => $this->team->slug]),
            [
                'conditions_json' => [
                    'all' => [
                        ['field' => 'risk_score', 'operator' => 'gte', 'value' => 0.8],
                    ],
                ],
            ],
        );

        $response->assertOk();
        $response->assertJsonPath('result', 'no_match');
        $response->assertJsonPath('checks.0.passed', false);
        $response->assertJsonPath('checks.0.expected', 0.8);
        $response->assertJsonPath('checks.0.actual', 0.2);
    }

    public function test_decision_test_without_events_reports_no_events(): void
    {
        $response = $this->actingAs($this->user)->postJson(
            route('rules.test.decision', ['current_team' => $this->team->slug]),
            [
                'conditions_json' => [
                    'all' => [
                        ['field' => 'risk_score', 'operator' => 'gte', 'value' => 0.5],
                    ],
                ],
            ],
        );

        $response->assertOk();
        $response->assertJsonPath('result', 'no_events');
    }

    public function test_decision_test_never_sees_another_tenants_evaluations(): void
    {
        // La única evaluación existente pertenece a otro team: para el team
        // actual el probador debe reportar no_events, jamás usarla.
        AIEventEvaluation::factory()->create([
            'team_id' => Team::factory()->create()->id,
            'risk_score' => 0.95,
        ]);

        $response = $this->actingAs($this->user)->postJson(
            route('rules.test.decision', ['current_team' => $this->team->slug]),
            [
                'conditions_json' => [
                    'all' => [
                        ['field' => 'risk_score', 'operator' => 'gte', 'value' => 0.5],
                    ],
                ],
            ],
        );

        $response->assertOk();
        $response->assertJsonPath('result', 'no_events');
    }

    public function test_decision_test_rejects_invalid_condition_tree(): void
    {
        $response = $this->actingAs($this->user)->postJson(
            route('rules.test.decision', ['current_team' => $this->team->slug]),
            [
                'conditions_json' => [
                    'all' => [
                        ['field' => 'risk_score', 'operator' => 'between', 'value' => 0.5],
                    ],
                ],
            ],
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['conditions_json']);
    }

    public function test_mapping_conditions_match_against_latest_raw_event(): void
    {
        RawEvent::factory()->create([
            'team_id' => $this->team->id,
            'payload_json' => [
                'data' => ['alert' => ['type' => 'panic_button']],
            ],
        ]);

        $response = $this->actingAs($this->user)->postJson(
            route('rules.test.mapping', ['current_team' => $this->team->slug]),
            [
                'external_conditions_json' => [
                    'data.alert.type' => 'panic_button',
                ],
            ],
        );

        $response->assertOk();
        $response->assertJsonPath('result', 'match');
        $response->assertJsonPath('checks.0.field', 'data.alert.type');
        $response->assertJsonPath('checks.0.actual', 'panic_button');
    }

    public function test_mapping_test_reports_no_match_on_different_payload(): void
    {
        RawEvent::factory()->create([
            'team_id' => $this->team->id,
            'payload_json' => [
                'data' => ['alert' => ['type' => 'harsh_braking']],
            ],
        ]);

        $response = $this->actingAs($this->user)->postJson(
            route('rules.test.mapping', ['current_team' => $this->team->slug]),
            [
                'external_conditions_json' => [
                    'data.alert.type' => 'panic_button',
                ],
            ],
        );

        $response->assertOk();
        $response->assertJsonPath('result', 'no_match');
        $response->assertJsonPath('checks.0.passed', false);
    }

    public function test_mapping_test_never_sees_another_tenants_raw_events(): void
    {
        RawEvent::factory()->create([
            'team_id' => Team::factory()->create()->id,
            'payload_json' => ['data' => ['alert' => ['type' => 'panic_button']]],
        ]);

        $response = $this->actingAs($this->user)->postJson(
            route('rules.test.mapping', ['current_team' => $this->team->slug]),
            [
                'external_conditions_json' => [
                    'data.alert.type' => 'panic_button',
                ],
            ],
        );

        $response->assertOk();
        $response->assertJsonPath('result', 'no_events');
    }
}
