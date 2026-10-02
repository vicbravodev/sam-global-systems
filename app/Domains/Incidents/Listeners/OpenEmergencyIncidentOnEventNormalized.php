<?php

namespace App\Domains\Incidents\Listeners;

use App\Domains\Assets\Jobs\DetectOfflineAssetsJob;
use App\Domains\Incidents\Enums\IncidentPriorityCode;
use App\Domains\Incidents\Jobs\OpenEmergencyIncidentJob;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Normalization\Actions\NormalizeRawEvent;
use App\Domains\Normalization\Events\EventNormalized;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Support\SystemLog;
use Illuminate\Support\Facades\DB;

/**
 * Carril rápido de emergencias (decisión 2026-09-28): un pánico, colisión o
 * vuelco abre su incidente crítico en cuanto se normaliza —y con él arranca la
 * llamada de verificación— sin esperar contexto, IA ni motor de decisión. La
 * IA llega después y sólo enriquece ese mismo incidente (reevaluación: nunca
 * baja la prioridad ni lo cierra). Si cualquier etapa posterior falla, el
 * incidente ya existe.
 */
class OpenEmergencyIncidentOnEventNormalized
{
    public function handle(EventNormalized $event): void
    {
        $normalized = $event->normalizedEvent;
        $normalizedEventId = $normalized->id;

        $type = EventType::query()
            ->select(['id', 'code'])
            ->find($normalized->event_type_id);

        // La categoría del evento normalizado, no la del tipo: una regla de
        // mapeo puede reclasificarlo (mapped_category_id) y la normalización
        // ya decidió con ésa (p.ej. no descartar una emergencia de un activo
        // no monitoreado).
        $categoryCode = EventCategory::query()
            ->whereKey($normalized->event_category_id)
            ->value('code');

        $input = [
            'normalized_event_id' => $normalizedEventId,
            'event_type_code' => $type?->code,
            'category_code' => $categoryCode,
        ];

        if (NormalizeRawEvent::isEmergencyCode($categoryCode, $type?->code)) {
            OpenEmergencyIncidentJob::dispatch($normalized->id, $normalized->team_id)
                ->afterCommit();

            DB::afterCommit(fn () => SystemLog::ok('incidents.emergency.fast_path',
                input: $input,
                calc: ['trigger' => 'emergency_code', 'was_in_motion' => null],
                result: ['priority_code' => IncidentPriorityCode::Critical->value, 'job_requested' => true],
            ));

            return;
        }

        // Unidad que se calla EN MOVIMIENTO (posible inhibidor o equipo
        // arrancado): incidente alto directo. El tipo `device_offline` es de
        // mantenimiento y se salta la IA, así que sin esto nunca abría
        // incidente. Una unidad estacionada que se calla sigue siendo sólo
        // registro (evita la fatiga de alertas nocturna).
        $wasInMotion = null;

        if ($type?->code === DetectOfflineAssetsJob::EVENT_TYPE_CODE) {
            $wasInMotion = $this->wentSilentInMotion($normalized);
        }

        if ($wasInMotion === true) {
            OpenEmergencyIncidentJob::dispatch($normalized->id, $normalized->team_id, IncidentPriorityCode::High->value)
                ->afterCommit();

            DB::afterCommit(fn () => SystemLog::ok('incidents.emergency.fast_path',
                input: $input,
                calc: ['trigger' => 'offline_in_motion', 'was_in_motion' => true],
                result: ['priority_code' => IncidentPriorityCode::High->value, 'job_requested' => true],
            ));

            return;
        }

        if ($wasInMotion === false) {
            DB::afterCommit(fn () => SystemLog::skipped('incidents.emergency.fast_path', reason: 'offline_parked', input: $input, calc: ['was_in_motion' => false]));

            return;
        }

        DB::afterCommit(fn () => SystemLog::skipped('incidents.emergency.fast_path', reason: 'not_emergency', input: $input, debug: true));
    }

    private function wentSilentInMotion(NormalizedEvent $normalized): bool
    {
        $payload = RawEvent::query()
            ->where('team_id', $normalized->team_id)
            ->whereKey($normalized->raw_event_id)
            ->value('payload_json');

        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }

        return is_array($payload) && ($payload['was_in_motion'] ?? false) === true;
    }
}
