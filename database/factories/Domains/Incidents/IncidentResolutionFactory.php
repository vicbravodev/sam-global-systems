<?php

namespace Database\Factories\Domains\Incidents;

use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\ResolutionCode;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentResolution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentResolution>
 */
class IncidentResolutionFactory extends Factory
{
    protected $model = IncidentResolution::class;

    public function definition(): array
    {
        return [
            'incident_id' => Incident::factory(),
            'resolution_code' => ResolutionCode::HandledSuccessfully,
            'resolution_summary' => fake()->paragraph(),
            'resolved_by_type' => IncidentCreatorType::User,
            'resolved_by_id' => null,
            'root_cause' => null,
            'corrective_action' => null,
            'preventive_action' => null,
            'resolved_at' => now(),
            'metadata_json' => null,
        ];
    }
}
