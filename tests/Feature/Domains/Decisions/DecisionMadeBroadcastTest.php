<?php

namespace Tests\Feature\Domains\Decisions;

use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Decisions\Actions\EvaluateDecisionRules;
use App\Domains\Decisions\Models\RuleSet;
use App\Domains\Decisions\Support\DecisionMadeBroadcast;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Database\Seeders\DecisionOutcomeSeeder;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DecisionMadeBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DecisionOutcomeSeeder::class);
        $this->seed(IncidentsSeeder::class);
    }

    public function test_broadcast_dispatched_on_private_accounts_channel(): void
    {
        Event::fake([DecisionMadeBroadcast::class]);

        $user = User::factory()->create();
        $teamId = $user->currentTeam->id;

        $event = NormalizedEvent::factory()->create(['team_id' => $teamId]);
        $eval = AIEventEvaluation::factory()->create([
            'normalized_event_id' => $event->id,
            'team_id' => $teamId,
            'classification' => EventClassification::RealEvent,
            'risk_score' => 0.7,
            'confidence_score' => 0.95,
        ]);

        RuleSet::factory()->global()->create(['code' => 'default']);

        app(EvaluateDecisionRules::class)->execute($eval);

        Event::assertDispatched(
            DecisionMadeBroadcast::class,
            function (DecisionMadeBroadcast $broadcast) use ($teamId) {
                $channels = $broadcast->broadcastOn();

                return $broadcast->teamId === $teamId
                    && $channels[0] instanceof PrivateChannel
                    && $channels[0]->name === "private-accounts.{$teamId}";
            },
        );
    }
}
