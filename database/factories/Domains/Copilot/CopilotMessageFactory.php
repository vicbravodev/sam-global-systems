<?php

namespace Database\Factories\Domains\Copilot;

use App\Domains\Copilot\Enums\CopilotIntent;
use App\Domains\Copilot\Enums\CopilotMessageRole;
use App\Domains\Copilot\Models\CopilotConversation;
use App\Domains\Copilot\Models\CopilotMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CopilotMessage>
 */
class CopilotMessageFactory extends Factory
{
    protected $model = CopilotMessage::class;

    public function definition(): array
    {
        return [
            'copilot_conversation_id' => CopilotConversation::factory(),
            // Same tenant and owner as the parent conversation.
            'team_id' => fn (array $attributes) => CopilotConversation::withoutGlobalScopes()->findOrFail((int) $attributes['copilot_conversation_id'])->team_id,
            'user_id' => fn (array $attributes) => CopilotConversation::withoutGlobalScopes()->findOrFail((int) $attributes['copilot_conversation_id'])->user_id,
            'role' => CopilotMessageRole::User,
            'content' => fake()->sentence(),
            'intent' => null,
            'channel' => 'page',
            'context_json' => null,
            'blocks_json' => null,
            'tools_json' => null,
            'sources_json' => null,
            'model' => null,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_estimate' => 0,
            'latency_ms' => 0,
            'feedback' => null,
        ];
    }

    public function assistant(CopilotIntent $intent = CopilotIntent::FleetOverview): static
    {
        return $this->state(fn () => [
            'role' => CopilotMessageRole::Assistant,
            'user_id' => null,
            'intent' => $intent,
            'blocks_json' => [],
            'tools_json' => [],
            'model' => 'gpt-test',
            'input_tokens' => 800,
            'output_tokens' => 200,
            'cost_estimate' => 0.0025,
            'latency_ms' => 900,
        ]);
    }
}
