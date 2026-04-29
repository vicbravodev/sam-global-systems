<?php

namespace Database\Factories\Domains\Automation;

use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Models\ActionTemplate;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActionTemplate>
 */
class ActionTemplateFactory extends Factory
{
    protected $model = ActionTemplate::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'code' => 'send_email_'.fake()->unique()->lexify('????'),
            'name' => 'Send Email Template',
            'action_type' => ActionType::SendEmail,
            'channel' => 'email',
            'subject_template' => 'Alert: {{event_type}}',
            'body_template' => 'Event {{event_id}} has occurred at {{location}}.',
            'parameters_schema_json' => [
                'event_type' => ['type' => 'string', 'required' => true],
                'event_id' => ['type' => 'integer', 'required' => true],
            ],
            'config_json' => null,
            'is_active' => true,
        ];
    }

    public function systemWide(): static
    {
        return $this->state(fn () => ['team_id' => null]);
    }

    public function ofType(ActionType $type): static
    {
        return $this->state(fn () => ['action_type' => $type]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function webhook(string $url = 'https://example.test/webhook'): static
    {
        return $this->state(fn () => [
            'action_type' => ActionType::CallWebhook,
            'channel' => 'http',
            'subject_template' => null,
            'body_template' => null,
            'config_json' => ['url' => $url, 'method' => 'POST', 'timeout_seconds' => 30],
        ]);
    }
}
