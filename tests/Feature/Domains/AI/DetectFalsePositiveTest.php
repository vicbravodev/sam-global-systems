<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Actions\DetectFalsePositive;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Events\FalsePositiveDetected;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DetectFalsePositiveTest extends TestCase
{
    use RefreshDatabase;

    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->teamId = $user->currentTeam->id;
    }

    public function test_dispatches_event_when_classification_is_false_positive_with_high_confidence(): void
    {
        Event::fake([FalsePositiveDetected::class]);

        $evaluation = AIEventEvaluation::factory()->create([
            'team_id' => $this->teamId,
            'classification' => EventClassification::FalsePositive,
            'confidence_score' => 0.85,
        ]);

        $result = app(DetectFalsePositive::class)->execute($evaluation);

        $this->assertTrue($result);
        Event::assertDispatched(FalsePositiveDetected::class);
    }

    public function test_does_not_dispatch_when_confidence_is_below_threshold(): void
    {
        Event::fake([FalsePositiveDetected::class]);

        $evaluation = AIEventEvaluation::factory()->create([
            'team_id' => $this->teamId,
            'classification' => EventClassification::FalsePositive,
            'confidence_score' => 0.40,
        ]);

        $result = app(DetectFalsePositive::class)->execute($evaluation);

        $this->assertFalse($result);
        Event::assertNotDispatched(FalsePositiveDetected::class);
    }

    public function test_does_not_dispatch_when_classification_is_not_false_positive(): void
    {
        Event::fake([FalsePositiveDetected::class]);

        $evaluation = AIEventEvaluation::factory()->create([
            'team_id' => $this->teamId,
            'classification' => EventClassification::RealEvent,
            'confidence_score' => 0.95,
        ]);

        $result = app(DetectFalsePositive::class)->execute($evaluation);

        $this->assertFalse($result);
        Event::assertNotDispatched(FalsePositiveDetected::class);
    }
}
