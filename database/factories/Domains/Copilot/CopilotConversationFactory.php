<?php

namespace Database\Factories\Domains\Copilot;

use App\Domains\Copilot\Models\CopilotConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CopilotConversation>
 */
class CopilotConversationFactory extends Factory
{
    protected $model = CopilotConversation::class;

    public function definition(): array
    {
        return [
            // The owner and the conversation live in the same tenant: the
            // user's current team, never a fresh unrelated one (§2.1 punto 9).
            'user_id' => User::factory(),
            'team_id' => fn (array $attributes) => User::query()->findOrFail($attributes['user_id'])->current_team_id,
            'title' => fake()->sentence(4),
            'is_pinned' => false,
            'messages_count' => 0,
            'last_message_at' => now(),
        ];
    }
}
