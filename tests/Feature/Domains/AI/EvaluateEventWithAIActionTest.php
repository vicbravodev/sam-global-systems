<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Actions\EvaluateEventWithAI;
use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Events\AIEvaluationCompleted;
use App\Domains\AI\Events\AIEvaluationCompletedBroadcast;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIExplanation;
use App\Domains\AI\Models\AIInferenceLog;
use App\Domains\AI\Models\AIRecommendedAction;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class EvaluateEventWithAIActionTest extends TestCase
{
    use RefreshDatabase;

    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->teamId = $user->currentTeam->id;
    }

    public function test_creates_full_evaluation_with_supporting_rows_and_dispatches_completion_events(): void
    {
        Event::fake([AIEvaluationCompleted::class, AIEvaluationCompletedBroadcast::class]);

        $severity = EventSeverity::factory()->create(['code' => 'high']);

        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'event_severity_id' => $severity->id,
        ]);

        $context = EventContextSnapshot::factory()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $event->id,
            'signals_json' => ['is_in_sensitive_geofence' => true],
        ]);

        $evaluation = app(EvaluateEventWithAI::class)->execute($event, $context);

        $this->assertInstanceOf(AIEventEvaluation::class, $evaluation);
        $this->assertSame($this->teamId, $evaluation->team_id);
        $this->assertSame(1, $evaluation->evaluation_version);
        $this->assertSame(EvaluationMode::RulesOnly, $evaluation->evaluation_mode);

        $this->assertSame(1, AIExplanation::query()->where('evaluation_id', $evaluation->id)->count());
        $this->assertSame(1, AIInferenceLog::query()->where('evaluation_id', $evaluation->id)->count());
        $this->assertGreaterThanOrEqual(1, AIRecommendedAction::query()->where('evaluation_id', $evaluation->id)->count());
        $this->assertNotNull($evaluation->recommended_action);

        Event::assertDispatched(AIEvaluationCompleted::class);
        Event::assertDispatched(AIEvaluationCompletedBroadcast::class);
    }

    public function test_increments_evaluation_version_when_invoked_twice(): void
    {
        Event::fake([AIEvaluationCompleted::class, AIEvaluationCompletedBroadcast::class]);

        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);
        $context = EventContextSnapshot::factory()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $event->id,
        ]);

        $first = app(EvaluateEventWithAI::class)->execute($event, $context, null, 1);
        $second = app(EvaluateEventWithAI::class)->execute($event, $context, null, 2);

        $this->assertSame(1, $first->evaluation_version);
        $this->assertSame(2, $second->evaluation_version);
        $this->assertNotSame($first->id, $second->id);
    }
}
