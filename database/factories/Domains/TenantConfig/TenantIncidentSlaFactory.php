<?php

namespace Database\Factories\Domains\TenantConfig;

use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\TenantConfig\Models\TenantIncidentSla;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantIncidentSlaFactory extends Factory
{
    protected $model = TenantIncidentSla::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'incident_priority_id' => IncidentPriority::factory(),
            'sla_seconds' => 900,
        ];
    }
}
