<?php

namespace App\Domains\Integrations\Actions;

use App\Support\LoggableCode;
use App\Support\SystemLog;

/**
 * Tipo de un webhook entrante para `webhook_events.event_type`.
 *
 * Samsara manda el tipo en el cuerpo (`eventType`, p. ej. `AlertIncident`) y
 * nunca un `?event_type=`: leer sólo ese campo guardaba `unknown` en todos los
 * webhooks reales. Prioridad: `eventType` del cuerpo → `event_type` del cuerpo
 * (forma legacy) → `unknown`. La query string nunca se lee: queda fuera del
 * HMAC. Gana el primer candidato que parece un código (`LoggableCode`); un
 * valor que no lo parece nunca se guarda tal cual.
 *
 * El valor sale de una petición sin autenticar (la firma se valida después, en
 * ProcessWebhookEventJob): es sólo informativo. Nunca decide tenant, proveedor
 * ni nada de seguridad; el tenant sale del WebhookEndpoint.
 */
class ResolveWebhookEventType
{
    public const string FALLBACK = 'unknown';

    public function execute(mixed $bodyEventType, mixed $legacyEventType, int $webhookEndpointId): string
    {
        $candidates = [
            'body_eventType' => $bodyEventType,
            'event_type' => $legacyEventType,
        ];
        $present = array_keys(array_filter($candidates, fn (mixed $value): bool => $value !== null && $value !== ''));
        $input = ['webhook_endpoint_id' => $webhookEndpointId];

        foreach ($present as $source) {
            $code = LoggableCode::guard($candidates[$source]);

            if ($code !== null) {
                SystemLog::ok('webhook.event_type.resolved', input: $input, calc: [
                    'source' => $source,
                    'candidates_present' => $present,
                ], result: ['event_type' => $code], debug: true);

                return $code;
            }
        }

        $reason = $present === [] ? 'missing' : 'not_a_code';

        SystemLog::degraded('webhook.event_type.resolved', reason: $reason, input: $input, calc: [
            'source' => 'fallback',
            'candidates_present' => $present,
        ], result: ['event_type' => self::FALLBACK]);

        return self::FALLBACK;
    }
}
