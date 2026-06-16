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
            ['code' => 'panic_emergency', 'name' => 'Emergencia de pánico', 'default_priority_id' => $critical?->id],
            ['code' => 'collision', 'name' => 'Colisión', 'default_priority_id' => $critical?->id],
            ['code' => 'camera_obstructed', 'name' => 'Cámara obstruida', 'default_priority_id' => $medium?->id],
            ['code' => 'route_deviation', 'name' => 'Desvío de ruta', 'default_priority_id' => $medium?->id],
            ['code' => 'geofence_breach', 'name' => 'Violación de geocerca', 'default_priority_id' => $high?->id],
            ['code' => 'driver_fatigue', 'name' => 'Fatiga del conductor', 'default_priority_id' => $high?->id],
            ['code' => 'suspicious_stop', 'name' => 'Parada sospechosa', 'default_priority_id' => $medium?->id],
            // Category-level buckets: every normalized event category resolves
            // to one of these when no specific incident type matches, so an
            // incident is never mislabeled as another type by fallback.
            ['code' => 'emergency_alert', 'name' => 'Alerta de emergencia', 'default_priority_id' => $critical?->id],
            ['code' => 'safety_violation', 'name' => 'Violación de seguridad', 'default_priority_id' => $high?->id],
            ['code' => 'compliance_violation', 'name' => 'Violación de cumplimiento', 'default_priority_id' => $medium?->id],
            ['code' => 'operational_alert', 'name' => 'Alerta operativa', 'default_priority_id' => $medium?->id],
            ['code' => 'other', 'name' => 'Otro', 'default_priority_id' => $medium?->id],
        ];

        foreach ($types as $type) {
            IncidentType::query()->updateOrCreate(
                ['code' => $type['code']],
                array_merge($type, ['is_active' => true]),
            );
        }
    }
}
