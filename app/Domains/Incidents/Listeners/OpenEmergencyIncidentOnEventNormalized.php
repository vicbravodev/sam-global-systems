<?php

namespace App\Domains\Incidents\Listeners;

use App\Domains\Assets\Jobs\DetectOfflineAssetsJob;
use App\Domains\Incidents\Enums\IncidentPriorityCode;
use App\Domains\Incidents\Jobs\OpenEmergencyIncidentJob;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Normalization\Actions\NormalizeRawEvent;
use App\Domains\Normalization\Events\EventNormalized;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;

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

        if ($normalized->event_type_id === null) {
            return;
        }

        $type = EventType::query()
            ->with('category:id,code')
            ->select(['id', 'code', 'category_id'])
            ->find($normalized->event_type_id);

        if (NormalizeRawEvent::isEmergencyCode($type?->category?->code, $type?->code)) {
            OpenEmergencyIncidentJob::dispatch((int) $normalized->id, (int) $normalized->team_id)
                ->afterCommit();

            return;
        }

        // Unidad que se calla EN MOVIMIENTO (posible inhibidor o equipo
        // arrancado): incidente alto directo. El tipo `device_offline` es de
        // mantenimiento y se salta la IA, así que sin esto nunca abría
        // incidente. Una unidad estacionada que se calla sigue siendo sólo
        // registro (evita la fatiga de alertas nocturna).
        if ($type?->code === DetectOfflineAssetsJob::EVENT_TYPE_CODE && $this->wentSilentInMotion($normalized)) {
            OpenEmergencyIncidentJob::dispatch((int) $normalized->id, (int) $normalized->team_id, IncidentPriorityCode::High->value)
                ->afterCommit();
        }
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
