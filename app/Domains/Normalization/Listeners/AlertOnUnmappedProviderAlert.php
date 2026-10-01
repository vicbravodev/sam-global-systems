<?php

namespace App\Domains\Normalization\Listeners;

use App\Domains\Ingestion\Actions\AlertPipelineFailure;
use App\Domains\Normalization\Events\EventUnmapped;
use App\Support\LoggableCode;
use App\Support\SystemLog;
use Throwable;

/**
 * Una alerta del proveedor (tipos de `pipeline.unmapped_alert_types`, p. ej.
 * `AlertIncident`, que es como llega el botón de pánico de Samsara) que se
 * normaliza como `unmapped` no abre incidente: sin este aviso, un pánico con
 * payload malformado se perdería en silencio.
 *
 * Síncrono a propósito: corre dentro de NormalizeEventJob (ya en un worker) y
 * AlertPipelineFailure envía con `sendNow`, sin depender de otra cola. Nunca
 * lanza: AlertPipelineFailure se protege y `handle` atrapa lo demás, así que
 * un aviso fallido nunca rompe la normalización (el evento ya quedó procesado).
 * El tenant sale del propio raw event; el dedup es por raw event.
 */
class AlertOnUnmappedProviderAlert
{
    public function __construct(
        private AlertPipelineFailure $alertPipelineFailure,
    ) {}

    public function handle(EventUnmapped $event): void
    {
        try {
            $this->escalate($event);
        } catch (Throwable $e) {
            SystemLog::failed('normalization.unmapped_alert.failed', reason: 'exception', input: [
                'raw_event_id' => $event->rawEvent->id,
                'team_id' => $event->rawEvent->team_id,
            ], error: $e);
        }
    }

    private function escalate(EventUnmapped $event): void
    {
        $rawEvent = $event->rawEvent;
        $input = [
            'raw_event_id' => $rawEvent->id,
            'team_id' => $rawEvent->team_id,
            'provider_id' => $event->providerId,
            'external_event_type' => LoggableCode::guard($event->externalEventType),
        ];

        /** @var array<int, string> $alertTypes */
        $alertTypes = (array) config('pipeline.unmapped_alert_types', []);
        $isAlertType = in_array($event->externalEventType, $alertTypes, true);

        if (! $isAlertType) {
            SystemLog::skipped('normalization.unmapped_alert.skipped', reason: 'not_alert_type', input: $input, calc: [
                'is_alert_type' => false,
                // guard() devuelve null o un código no vacío; se conserva el
                // descarte de '0' que hacía el array_filter sin callback.
                'alert_types' => array_values(array_filter(
                    array_map(LoggableCode::guard(...), $alertTypes),
                    fn (?string $code): bool => $code !== null && $code !== '0',
                )),
            ], debug: true);

            return;
        }

        SystemLog::degraded('normalization.unmapped_alert.escalated', reason: 'alert_type_unmapped', input: $input, calc: [
            'is_alert_type' => true,
        ]);

        $this->alertPipelineFailure->forUnmappedAlert($rawEvent, $event->externalEventType);
    }
}
