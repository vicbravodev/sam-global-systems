<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Enums\ReevaluationTrigger;
use App\Domains\AI\Jobs\ReevaluateEventJob;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIInferenceLog;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class AIEvaluationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
    }

    public function test_index_returns_paginated_evaluations_for_team_member(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);
        AIEventEvaluation::factory()->create([
            'normalized_event_id' => $event->id,
            'team_id' => $team->id,
        ]);

        $this->actingAs($user);

        $response = $this->getJson("/api/{$team->slug}/ai/evaluations");

        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));
    }

    public function test_show_returns_evaluation_with_related_records(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);
        $evaluation = AIEventEvaluation::factory()->create([
            'normalized_event_id' => $event->id,
            'team_id' => $team->id,
        ]);

        $this->actingAs($user);

        $response = $this->getJson("/api/{$team->slug}/ai/evaluations/{$evaluation->id}");

        $response->assertOk();
        $this->assertSame($evaluation->id, $response->json('data.id'));
    }

    public function test_show_hides_prompt_snapshot_raw_output_and_cost_of_inference_logs(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);
        $evaluation = AIEventEvaluation::factory()->create([
            'normalized_event_id' => $event->id,
            'team_id' => $team->id,
        ]);
        AIInferenceLog::factory()->create([
            'evaluation_id' => $evaluation->id,
            'input_snapshot_json' => ['prompt' => 'contexto interno'],
            'output_json' => ['classification' => 'real_event'],
            'cost_estimate' => 0.0123,
            'latency_ms' => 850,
        ]);

        $this->actingAs($user);

        $log = $this->getJson("/api/{$team->slug}/ai/evaluations/{$evaluation->id}")
            ->assertOk()
            ->json('data.inference_logs.0');

        $this->assertSame(850, $log['latency_ms']);
        $this->assertArrayNotHasKey('input_snapshot_json', $log);
        $this->assertArrayNotHasKey('output_json', $log);
        $this->assertArrayNotHasKey('cost_estimate', $log);
    }

    public function test_index_caps_per_page(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $this->actingAs($user);

        $this->getJson("/api/{$team->slug}/ai/evaluations?per_page=100000")
            ->assertOk()
            ->assertJsonPath('per_page', 100);
    }

    public function test_reevaluate_endpoint_dispatches_job(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;

        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);
        $evaluation = AIEventEvaluation::factory()->create([
            'normalized_event_id' => $event->id,
            'team_id' => $team->id,
        ]);

        $this->actingAs($user);

        $response = $this->postJson("/api/{$team->slug}/ai/evaluations/{$evaluation->id}/reevaluate", [
            'reason' => 'manual trigger',
        ]);

        $response->assertStatus(202);

        Bus::assertDispatched(ReevaluateEventJob::class, function (ReevaluateEventJob $job) use ($event) {
            return $job->normalizedEventId === $event->id
                && $job->triggerType === ReevaluationTrigger::ManualReviewRequested->value;
        });
    }

    public function test_cross_tenant_access_is_blocked(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $event = NormalizedEvent::factory()->create(['team_id' => $userA->currentTeam->id]);
        $evaluation = AIEventEvaluation::factory()->create([
            'normalized_event_id' => $event->id,
            'team_id' => $userA->currentTeam->id,
        ]);

        $this->actingAs($userB);

        $response = $this->getJson("/api/{$userA->currentTeam->slug}/ai/evaluations/{$evaluation->id}");

        $this->assertContains($response->status(), [403, 404]);
    }
}
