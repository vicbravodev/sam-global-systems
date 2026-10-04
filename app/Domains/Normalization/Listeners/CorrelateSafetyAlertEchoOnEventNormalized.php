<?php

namespace App\Domains\Normalization\Listeners;

use App\Domains\Normalization\Actions\CorrelateSafetyAlertEcho;
use App\Domains\Normalization\Events\EventNormalized;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Throwable;

/**
 * Síncrono: son un par de consultas acotadas por tenant, unidad y ventana, y
 * corre dentro del worker de normalización. Nunca rompe la normalización: el
 * evento ya quedó guardado.
 */
class CorrelateSafetyAlertEchoOnEventNormalized
{
    public function __construct(
        private CorrelateSafetyAlertEcho $correlate,
    ) {}

    public function handle(EventNormalized $event): void
    {
        $normalizedEvent = $event->normalizedEvent;

        try {
            TenantContext::for($normalizedEvent->team_id, fn () => $this->correlate->execute($normalizedEvent));
        } catch (Throwable $e) {
            SystemLog::failed('normalization.safety_echo.failed', reason: 'exception', input: [
                'normalized_event_id' => $normalizedEvent->id,
            ], error: $e);
        }
    }
}
