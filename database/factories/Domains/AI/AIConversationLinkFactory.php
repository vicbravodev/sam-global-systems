<?php

namespace Database\Factories\Domains\AI;

use App\Domains\AI\Models\AIConversationLink;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AIConversationLink>
 */
class AIConversationLinkFactory extends Factory
{
    protected $model = AIConversationLink::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'agent_conversation_id' => fake()->unique()->numberBetween(1, 1_000_000),
            'normalized_event_id' => null,
            'evaluation_id' => null,
            'purpose' => 'event_evaluation',
            'metadata_json' => null,
        ];
    }
}
