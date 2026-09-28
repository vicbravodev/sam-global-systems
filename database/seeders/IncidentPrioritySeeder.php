<?php

namespace Database\Seeders;

use App\Domains\Incidents\Models\IncidentPriority;
use Illuminate\Database\Seeder;

class IncidentPrioritySeeder extends Seeder
{
    public function run(): void
    {
        $priorities = [
            ['code' => 'low', 'name' => 'Baja', 'level' => 1, 'sla_seconds' => null, 'color' => '#6B7280'],
            ['code' => 'medium', 'name' => 'Media', 'level' => 2, 'sla_seconds' => 3600, 'color' => '#F59E0B'],
            ['code' => 'high', 'name' => 'Alta', 'level' => 3, 'sla_seconds' => 1800, 'color' => '#EF4444'],
            ['code' => 'critical', 'name' => 'Crítica', 'level' => 4, 'sla_seconds' => 300, 'color' => '#991B1B'],
        ];

        foreach ($priorities as $priority) {
            IncidentPriority::query()->updateOrCreate(['code' => $priority['code']], $priority);
        }
    }
}
