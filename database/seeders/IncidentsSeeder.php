<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class IncidentsSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(IncidentPrioritySeeder::class);
        $this->call(IncidentStatusSeeder::class);
        $this->call(IncidentTypeSeeder::class);
        $this->call(IncidentMeterSeeder::class);
    }
}
