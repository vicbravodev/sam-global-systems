<?php

namespace Database\Factories\Domains\Incidents;

use App\Domains\Incidents\Enums\EvidenceSourceType;
use App\Domains\Incidents\Enums\EvidenceType;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentEvidence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentEvidence>
 */
class IncidentEvidenceFactory extends Factory
{
    protected $model = IncidentEvidence::class;

    public function definition(): array
    {
        return [
            'incident_id' => Incident::factory(),
            'evidence_type' => EvidenceType::EventSnapshot,
            'source_type' => EvidenceSourceType::EventContext,
            'source_reference_id' => null,
            'title' => fake()->words(3, true),
            'description' => null,
            'file_url' => null,
            'storage_path' => null,
            'metadata_json' => null,
            'added_by_type' => IncidentCreatorType::System,
            'added_by_id' => null,
        ];
    }
}
