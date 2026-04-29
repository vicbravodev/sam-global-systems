<?php

namespace Database\Factories\Domains\Incidents;

use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentTimeline;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentTimeline>
 */
class IncidentTimelineFactory extends Factory
{
    protected $model = IncidentTimeline::class;

    public function definition(): array
    {
        return [
            'incident_id' => Incident::factory(),
            'entry_type' => TimelineEntryType::Created,
            'actor_type' => TimelineActorType::System,
            'actor_id' => null,
            'title' => 'Incident created',
            'description' => null,
            'payload_json' => null,
            'occurred_at' => now(),
        ];
    }
}
