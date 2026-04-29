<?php

namespace Database\Factories\Domains\Incidents;

use App\Domains\Incidents\Enums\AssigneeType;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentAssignment>
 */
class IncidentAssignmentFactory extends Factory
{
    protected $model = IncidentAssignment::class;

    public function definition(): array
    {
        return [
            'incident_id' => Incident::factory(),
            'assigned_to_type' => AssigneeType::User,
            'assigned_to_id' => 1,
            'role' => null,
            'assigned_at' => now(),
            'unassigned_at' => null,
            'assigned_by_type' => IncidentCreatorType::System,
            'assigned_by_id' => null,
            'metadata_json' => null,
        ];
    }
}
