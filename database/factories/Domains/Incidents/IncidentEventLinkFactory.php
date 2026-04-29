<?php

namespace Database\Factories\Domains\Incidents;

use App\Domains\Incidents\Enums\EventRelationType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentEventLink;
use App\Domains\Normalization\Models\NormalizedEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentEventLink>
 */
class IncidentEventLinkFactory extends Factory
{
    protected $model = IncidentEventLink::class;

    public function definition(): array
    {
        return [
            'incident_id' => Incident::factory(),
            'normalized_event_id' => NormalizedEvent::factory(),
            'relation_type' => EventRelationType::SupportingEvent,
        ];
    }
}
