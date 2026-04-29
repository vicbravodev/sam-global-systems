<?php

namespace Tests\Feature\Domains\Decisions;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Decisions\Actions\OverrideDecision;
use App\Domains\Decisions\Enums\DecisionOutcomeCode;
use App\Domains\Decisions\Enums\DecisionSourceType;
use App\Domains\Decisions\Events\DecisionOverridden;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Decisions\Models\DecisionOutcome;
use App\Domains\Decisions\Models\DecisionTrace;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Database\Seeders\DecisionOutcomeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Tests\TestCase;

class OverrideDecisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DecisionOutcomeSeeder::class);
    }

    public function test_manual_override_creates_record_and_updates_decision(): void
    {
        Event::fake([DecisionOverridden::class]);

        $user = User::factory()->create();
        $teamId = $user->currentTeam->id;

        $event = NormalizedEvent::factory()->create(['team_id' => $teamId]);
        $eval = AIEventEvaluation::factory()->create([
            'normalized_event_id' => $event->id,
            'team_id' => $teamId,
        ]);

        $logOnly = DecisionOutcome::firstWhere('code', DecisionOutcomeCode::LogOnly->value);

        $decision = Decision::factory()->create([
            'normalized_event_id' => $event->id,
            'team_id' => $teamId,
            'ai_evaluation_id' => $eval->id,
            'decision_code' => DecisionOutcomeCode::LogOnly->value,
            'outcome_id' => $logOnly->id,
        ]);

        $override = app(OverrideDecision::class)->execute(
            $decision,
            $user,
            DecisionOutcomeCode::Incident->value,
            'Manual review concluded that the event is genuine.',
        );

        $this->assertSame(DecisionOutcomeCode::LogOnly->value, $override->previous_outcome);
        $this->assertSame(DecisionOutcomeCode::Incident->value, $override->new_outcome);

        $decision->refresh();
        $this->assertSame(DecisionOutcomeCode::Incident->value, $decision->decision_code);
        $this->assertFalse($decision->is_automated);

        $this->assertTrue(
            DecisionTrace::where('decision_id', $decision->id)
                ->where('source_type', DecisionSourceType::ManualOverride)
                ->exists(),
        );

        Event::assertDispatched(DecisionOverridden::class);
    }

    public function test_override_with_unknown_outcome_throws(): void
    {
        $user = User::factory()->create();
        $teamId = $user->currentTeam->id;

        $event = NormalizedEvent::factory()->create(['team_id' => $teamId]);
        $eval = AIEventEvaluation::factory()->create([
            'normalized_event_id' => $event->id,
            'team_id' => $teamId,
        ]);
        $logOnly = DecisionOutcome::firstWhere('code', DecisionOutcomeCode::LogOnly->value);

        $decision = Decision::factory()->create([
            'normalized_event_id' => $event->id,
            'team_id' => $teamId,
            'ai_evaluation_id' => $eval->id,
            'outcome_id' => $logOnly->id,
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(OverrideDecision::class)->execute($decision, $user, 'BOGUS_CODE', 'because');
    }
}
