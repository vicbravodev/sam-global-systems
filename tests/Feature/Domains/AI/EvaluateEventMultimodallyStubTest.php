<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Actions\EvaluateEventMultimodally;
use App\Domains\AI\Jobs\EvaluateEventMediaJob;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Guards the SPEC-09-PR2-DEFERRED stubs: multimodal evaluation and the media
 * evaluation job must remain no-ops until spec 09 PR #2 lands.
 */
class EvaluateEventMultimodallyStubTest extends TestCase
{
    use RefreshDatabase;

    public function test_multimodal_action_returns_null_for_any_evaluation_and_media_set(): void
    {
        $user = User::factory()->create();
        $evaluation = AIEventEvaluation::factory()->create(['team_id' => $user->currentTeam->id]);

        $result = (new EvaluateEventMultimodally)->execute($evaluation, new Collection);

        $this->assertNull($result);
    }

    public function test_media_job_is_constructible_and_queue_is_ai_evaluation(): void
    {
        $job = new EvaluateEventMediaJob(99);

        $this->assertSame('ai-evaluation', $job->queue);
    }
}
