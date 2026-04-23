<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Actions\ReevaluateEventWithNewEvidence;
use App\Domains\AI\Enums\ReevaluationStatus;
use App\Domains\AI\Jobs\ReevaluateEventJob;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIReevaluationRequest;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Models\User;
use Database\Seeders\AiUsageMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReevaluateEventJobTest extends TestCase
{
    use RefreshDatabase;

    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AiUsageMeterSeeder::class);

        $user = User::factory()->create();
        $this->teamId = $user->currentTeam->id;
    }

    public function test_handle_creates_new_evaluation_version_and_marks_request_completed(): void
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);

        EventContextSnapshot::factory()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $event->id,
        ]);

        AIEventEvaluation::factory()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $event->id,
            'evaluation_version' => 1,
        ]);

        $request = AIReevaluationRequest::factory()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $event->id,
            'status' => ReevaluationStatus::Pending,
        ]);

        (new ReevaluateEventJob($request->id))->handle(
            app(ReevaluateEventWithNewEvidence::class),
            app(RecordUsageEvent::class),
        );

        $request->refresh();

        $this->assertSame(ReevaluationStatus::Completed, $request->status);
        $this->assertNotNull($request->processed_at);
        $this->assertSame(
            2,
            AIEventEvaluation::withoutGlobalScopes()
                ->where('normalized_event_id', $event->id)
                ->max('evaluation_version'),
        );
    }

    public function test_handle_returns_early_when_request_not_pending(): void
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);

        $request = AIReevaluationRequest::factory()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $event->id,
            'status' => ReevaluationStatus::Completed,
        ]);

        (new ReevaluateEventJob($request->id))->handle(
            app(ReevaluateEventWithNewEvidence::class),
            app(RecordUsageEvent::class),
        );

        $this->assertSame(0, AIEventEvaluation::withoutGlobalScopes()->count());
    }
}
