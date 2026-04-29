<?php

namespace Database\Seeders;

use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Incidents\Models\IncidentType;
use Illuminate\Database\Seeder;

class IncidentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $high = IncidentPriority::query()->where('code', 'high')->first();
        $critical = IncidentPriority::query()->where('code', 'critical')->first();
        $medium = IncidentPriority::query()->where('code', 'medium')->first();

        $types = [
            ['code' => 'panic_emergency', 'name' => 'Panic Emergency', 'default_priority_id' => $critical?->id],
            ['code' => 'collision', 'name' => 'Collision', 'default_priority_id' => $critical?->id],
            ['code' => 'camera_obstructed', 'name' => 'Camera Obstructed', 'default_priority_id' => $medium?->id],
            ['code' => 'route_deviation', 'name' => 'Route Deviation', 'default_priority_id' => $medium?->id],
            ['code' => 'geofence_breach', 'name' => 'Geofence Breach', 'default_priority_id' => $high?->id],
            ['code' => 'driver_fatigue', 'name' => 'Driver Fatigue', 'default_priority_id' => $high?->id],
            ['code' => 'suspicious_stop', 'name' => 'Suspicious Stop', 'default_priority_id' => $medium?->id],
        ];

        foreach ($types as $type) {
            IncidentType::query()->updateOrCreate(
                ['code' => $type['code']],
                array_merge($type, ['is_active' => true]),
            );
        }
    }
}
