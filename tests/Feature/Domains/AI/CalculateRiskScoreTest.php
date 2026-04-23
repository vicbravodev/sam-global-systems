<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Actions\CalculateRiskScore;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalculateRiskScoreTest extends TestCase
{
    use RefreshDatabase;

    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->teamId = $user->currentTeam->id;
    }

    public function test_higher_severity_produces_higher_score_than_low_severity(): void
    {
        $high = EventSeverity::factory()->create(['code' => 'high']);
        $low = EventSeverity::factory()->create(['code' => 'low']);

        $highEvent = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'event_severity_id' => $high->id,
        ]);
        $lowEvent = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'event_severity_id' => $low->id,
        ]);

        $highContext = EventContextSnapshot::factory()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $highEvent->id,
            'signals_json' => [],
        ]);
        $lowContext = EventContextSnapshot::factory()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $lowEvent->id,
            'signals_json' => [],
        ]);

        $action = app(CalculateRiskScore::class);

        $highScore = $action->execute($highEvent, $highContext, null);
        $lowScore = $action->execute($lowEvent, $lowContext, null);

        $this->assertGreaterThan($lowScore, $highScore);
    }

    public function test_recent_high_severity_history_adds_bonus(): void
    {
        $severity = EventSeverity::factory()->create(['code' => 'medium']);

        $baseEvent = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'event_severity_id' => $severity->id,
        ]);
        $boostedEvent = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'event_severity_id' => $severity->id,
        ]);

        $baseContext = EventContextSnapshot::factory()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $baseEvent->id,
            'signals_json' => [],
        ]);

        $boostedContext = EventContextSnapshot::factory()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $boostedEvent->id,
            'signals_json' => [
                'recent_high_severity_count' => 3,
                'recent_same_type_count' => 4,
            ],
        ]);

        $action = app(CalculateRiskScore::class);

        $base = $action->execute($baseEvent, $baseContext, null);
        $boosted = $action->execute($boostedEvent, $boostedContext, null);

        $this->assertGreaterThan($base, $boosted);
    }

    public function test_score_is_clamped_between_zero_and_one(): void
    {
        $severity = EventSeverity::factory()->create(['code' => 'critical']);

        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'event_severity_id' => $severity->id,
        ]);

        $context = EventContextSnapshot::factory()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $event->id,
            'signals_json' => [
                'recent_high_severity_count' => 10,
                'recent_same_type_count' => 10,
            ],
        ]);

        $score = app(CalculateRiskScore::class)->execute($event, $context, null);

        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertLessThanOrEqual(1.0, $score);
    }
}
