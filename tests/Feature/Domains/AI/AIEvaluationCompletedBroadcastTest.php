<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Actions\EvaluateEventWithAI;
use App\Domains\AI\Support\AIEvaluationCompletedBroadcast;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AIEvaluationCompletedBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AIMeterSeeder::class);
    }

    public function test_broadcast_dispatched_on_private_accounts_channel_when_evaluation_completes(): void
    {
        Event::fake([AIEvaluationCompletedBroadcast::class]);

        $user = User::factory()->create();
        $team = $user->currentTeam;

        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'payload_normalized_json' => ['severity' => 'high'],
        ]);

        app(EvaluateEventWithAI::class)->execute($event);

        Event::assertDispatched(
            AIEvaluationCompletedBroadcast::class,
            function (AIEvaluationCompletedBroadcast $broadcast) use ($team) {
                $channels = $broadcast->broadcastOn();

                return $broadcast->teamId === $team->id
                    && $channels[0] instanceof PrivateChannel
                    && $channels[0]->name === "private-accounts.{$team->id}";
            }
        );
    }
}
