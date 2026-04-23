<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Actions\ReevaluateEventWithNewEvidence;
use App\Domains\AI\Enums\ReevaluationStatus;
use App\Domains\AI\Enums\ReevaluationTrigger;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIReevaluationRequest;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReevaluateEventTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AIMeterSeeder::class);
    }

    public function test_reevaluation_increments_version(): void
    {
        $user = User::factory()->create();
        $event = NormalizedEvent::factory()->create([
            'team_id' => $user->currentTeam->id,
            'payload_normalized_json' => ['severity' => 'high'],
        ]);

        AIEventEvaluation::factory()->create([
            'normalized_event_id' => $event->id,
            'team_id' => $user->currentTeam->id,
            'evaluation_version' => 1,
        ]);

        $evaluation = app(ReevaluateEventWithNewEvidence::class)->execute(
            event: $event,
            trigger: ReevaluationTrigger::ManualReviewRequested,
            reason: 'operator wants a second look',
        );

        $this->assertSame(2, $evaluation->evaluation_version);

        $versions = AIEventEvaluation::withoutGlobalScopes()
            ->where('normalized_event_id', $event->id)
            ->pluck('evaluation_version')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([1, 2], $versions);
    }

    public function test_reevaluation_deduplicates_pending_requests(): void
    {
        $user = User::factory()->create();
        $event = NormalizedEvent::factory()->create([
            'team_id' => $user->currentTeam->id,
            'payload_normalized_json' => ['severity' => 'medium'],
        ]);

        AIReevaluationRequest::create([
            'normalized_event_id' => $event->id,
            'trigger_type' => ReevaluationTrigger::ManualReviewRequested,
            'status' => ReevaluationStatus::Pending,
            'requested_at' => now(),
        ]);

        app(ReevaluateEventWithNewEvidence::class)->execute(
            event: $event,
            trigger: ReevaluationTrigger::ManualReviewRequested,
        );

        $skipped = AIReevaluationRequest::where('normalized_event_id', $event->id)
            ->where('status', ReevaluationStatus::Skipped)
            ->count();

        $completed = AIReevaluationRequest::where('normalized_event_id', $event->id)
            ->where('status', ReevaluationStatus::Completed)
            ->count();

        $this->assertSame(1, $skipped);
        $this->assertSame(1, $completed);
    }
}
