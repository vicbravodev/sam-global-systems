<?php

namespace Database\Factories\Domains\Incidents;

use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\IncidentSourceType;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Incidents\Models\IncidentStatus;
use App\Domains\Incidents\Models\IncidentType;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Incident>
 */
class IncidentFactory extends Factory
{
    protected $model = Incident::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'incident_type_id' => fn () => $this->resolveLookup(IncidentType::class, ['code' => 'auto_type', 'name' => 'Auto Type', 'is_active' => true])->id,
            'incident_status_id' => fn () => $this->resolveLookup(IncidentStatus::class, ['code' => IncidentStatusCode::Open->value, 'name' => 'Open', 'is_terminal' => false, 'sort_order' => 1])->id,
            'incident_priority_id' => fn () => $this->resolveLookup(IncidentPriority::class, ['code' => 'medium', 'name' => 'Medium', 'level' => 2, 'sla_seconds' => 3600, 'color' => '#F59E0B'])->id,
            'source_type' => IncidentSourceType::Manual,
            'source_reference_id' => null,
            'related_event_id' => null,
            'related_decision_id' => null,
            'asset_id' => null,
            'driver_id' => null,
            'title' => fake()->sentence(4),
            'summary' => fake()->paragraph(2),
            'description' => null,
            'opened_at' => now(),
            'created_by_type' => IncidentCreatorType::System,
            'created_by_id' => null,
            'metadata_json' => null,
        ];
    }

    public function open(): static
    {
        return $this->state(fn () => [
            'incident_status_id' => $this->resolveLookup(IncidentStatus::class, [
                'code' => IncidentStatusCode::Open->value,
                'name' => 'Open',
                'is_terminal' => false,
                'sort_order' => 1,
            ])->id,
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'incident_status_id' => $this->resolveLookup(IncidentStatus::class, [
                'code' => IncidentStatusCode::Resolved->value,
                'name' => 'Resolved',
                'is_terminal' => true,
                'sort_order' => 4,
            ])->id,
            'resolved_at' => now(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'incident_status_id' => $this->resolveLookup(IncidentStatus::class, [
                'code' => IncidentStatusCode::Closed->value,
                'name' => 'Closed',
                'is_terminal' => true,
                'sort_order' => 5,
            ])->id,
            'resolved_at' => now()->subMinute(),
            'closed_at' => now(),
        ]);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TModel>  $modelClass
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    private function resolveLookup(string $modelClass, array $attributes)
    {
        return $modelClass::query()->firstOrCreate(
            ['code' => $attributes['code']],
            $attributes,
        );
    }
}
