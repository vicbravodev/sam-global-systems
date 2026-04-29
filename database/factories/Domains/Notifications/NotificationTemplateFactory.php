<?php

namespace Database\Factories\Domains\Notifications;

use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationTemplate;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationTemplate>
 */
class NotificationTemplateFactory extends Factory
{
    protected $model = NotificationTemplate::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'code' => 'tpl_'.fake()->unique()->slug(2),
            'name' => 'Test Template',
            'channel_type' => ChannelType::Email,
            'event_type' => 'incident.created',
            'priority' => null,
            'subject_template' => 'Incident: {{ $incident_type }}',
            'body_template' => 'A new incident of type {{ $incident_type }} was reported on asset {{ $asset_name }}.',
            'variables_schema_json' => ['incident_type' => 'string', 'asset_name' => 'string'],
            'locale' => 'en',
            'is_active' => true,
        ];
    }

    public function email(): static
    {
        return $this->state(fn () => ['channel_type' => ChannelType::Email]);
    }

    public function sms(): static
    {
        return $this->state(fn () => [
            'channel_type' => ChannelType::Sms,
            'subject_template' => null,
            'body_template' => 'Incident {{ $incident_type }} on {{ $asset_name }}',
        ]);
    }
}
