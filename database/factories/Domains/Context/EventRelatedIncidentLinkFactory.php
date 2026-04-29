<?php

namespace Database\Factories\Domains\Context;

use App\Domains\Context\Enums\IncidentRelationType;
use App\Domains\Context\Models\EventRelatedIncidentLink;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventRelatedIncidentLink>
 */
class EventRelatedIncidentLinkFactory extends Factory
{
    protected $model = EventRelatedIncidentLink::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'normalized_event_id' => NormalizedEvent::factory(),
            'incident_id' => Incident::factory(),
            'relation_type' => IncidentRelationType::SameAssetOpenIncident,
            'confidence_score' => 0.80,
        ];
    }
}
