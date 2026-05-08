<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Support\AIStreamTaskRegistry;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AIStreamControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_stream_returns_404_when_task_id_unknown(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('api.ai.tasks.stream', [
                'current_team' => $user->currentTeam->slug,
                'taskId' => 'nonexistent-task',
            ]))
            ->assertNotFound();
    }

    public function test_stream_returns_404_when_team_mismatch(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $taskId = AIStreamTaskRegistry::register(
            teamId: $owner->currentTeam->id,
            userId: $owner->id,
        );

        $this->actingAs($intruder)
            ->get(route('api.ai.tasks.stream', [
                'current_team' => $intruder->currentTeam->slug,
                'taskId' => $taskId,
            ]))
            ->assertNotFound();
    }

    public function test_stream_returns_404_when_user_mismatch(): void
    {
        $owner = User::factory()->create();
        $sibling = User::factory()->create();
        $sibling->teams()->attach($owner->currentTeam, ['role' => 'member']);

        $taskId = AIStreamTaskRegistry::register(
            teamId: $owner->currentTeam->id,
            userId: $owner->id,
        );

        $sibling->forceFill(['current_team_id' => $owner->currentTeam->id])->save();

        $this->actingAs($sibling)
            ->get(route('api.ai.tasks.stream', [
                'current_team' => $owner->currentTeam->slug,
                'taskId' => $taskId,
            ]))
            ->assertNotFound();
    }

    public function test_stream_emits_completed_event_for_resolved_evaluation(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);
        $evaluation = AIEventEvaluation::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
            'explanation_text' => 'Final explanation.',
        ]);

        $taskId = AIStreamTaskRegistry::register(
            teamId: $team->id,
            userId: $user->id,
            evaluationId: $evaluation->id,
            normalizedEventId: $event->id,
        );

        $response = $this->actingAs($user)
            ->get(route('api.ai.tasks.stream', [
                'current_team' => $team->slug,
                'taskId' => $taskId,
            ]));

        $response->assertOk();
        $this->assertStringStartsWith('text/event-stream', (string) $response->headers->get('Content-Type'));

        $body = $response->streamedContent();

        $this->assertStringContainsString('event: start', $body);
        $this->assertStringContainsString('event: progress', $body);
        $this->assertStringContainsString('event: end', $body);
        $this->assertStringContainsString('"task_id":"'.$taskId.'"', $body);
        $this->assertStringContainsString('"evaluation_id":'.$evaluation->id, $body);
    }

    public function test_registry_round_trips_payload_within_ttl(): void
    {
        $user = User::factory()->create();

        $taskId = AIStreamTaskRegistry::register(
            teamId: $user->currentTeam->id,
            userId: $user->id,
            evaluationId: 99,
        );

        $payload = AIStreamTaskRegistry::resolve($taskId);

        $this->assertNotNull($payload);
        $this->assertSame($user->currentTeam->id, $payload['team_id']);
        $this->assertSame($user->id, $payload['user_id']);
        $this->assertSame(99, $payload['evaluation_id']);
    }
}
