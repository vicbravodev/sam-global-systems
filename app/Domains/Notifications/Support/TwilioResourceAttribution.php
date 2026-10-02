<?php

namespace App\Domains\Notifications\Support;

use App\Domains\Incidents\Models\IncidentCallVerification;
use App\Domains\Notifications\Models\MessagingCharge;
use App\Domains\Notifications\Models\NotificationDelivery;
use DateTimeInterface;

/**
 * ¿Cuál de los mensajes/llamadas que Twilio tiene hacia un destino es el de
 * un envío cuyo resultado no se supo (timeout)? El número de plataforma lo
 * comparten todos los tenants, así que la respuesta debe ser inequívoca o
 * no hay respuesta:
 *
 * - Sólo recursos creados dentro de la ventana del intento (ambos lados).
 * - Fuera todo SID que ya pertenezca a algo (cargo, entrega o verificación,
 *   de cualquier tenant).
 * - En mensajes, sólo los que llevan exactamente el texto enviado.
 * - Exactamente un candidato; con cero o varios, "no encontrado" (el
 *   reintento normal decide). Mejor un posible doble envío que adoptar el
 *   recurso de otro tenant y perder el aviso real.
 */
final class TwilioResourceAttribution
{
    /**
     * @param  list<object>  $candidates  recursos Twilio (`sid`, `dateCreated`, `body` en mensajes)
     * @return array{resource: ?object, unclaimed_count: int}
     */
    public static function pick(array $candidates, DateTimeInterface $since, DateTimeInterface $until, ?string $body = null): array
    {
        $inWindow = array_values(array_filter($candidates, fn (object $c): bool => is_string($c->sid ?? null)
            && $c->sid !== ''
            && ($c->dateCreated ?? null) instanceof DateTimeInterface
            && $c->dateCreated >= $since
            && $c->dateCreated <= $until));

        $sids = array_map(fn (object $c): string => (string) ($c->sid ?? ''), $inWindow);
        $taken = self::takenSids($sids);
        $body = $body !== null ? trim($body) : null;

        $unclaimed = array_values(array_filter($inWindow, fn (object $c): bool => ! in_array((string) ($c->sid ?? ''), $taken, true)
            && ($body === null || ! isset($c->body) || trim((string) $c->body) === $body)));

        return [
            'resource' => count($unclaimed) === 1 ? $unclaimed[0] : null,
            'unclaimed_count' => count($unclaimed),
        ];
    }

    /**
     * SIDs que ya pertenecen a algo registrado en SAM. Consulta de plataforma
     * por SID (único entre tenants): sólo dice cuáles están tomados, nunca
     * lee ni toca esas filas.
     *
     * @param  list<string>  $sids
     * @return list<string>
     */
    private static function takenSids(array $sids): array
    {
        if ($sids === []) {
            return [];
        }

        return array_values(array_unique([
            ...MessagingCharge::withoutGlobalScopes()->whereIn('provider_sid', $sids)->pluck('provider_sid')->all(),
            ...NotificationDelivery::withoutGlobalScopes()->whereIn('provider_message_id', $sids)->pluck('provider_message_id')->all(),
            ...IncidentCallVerification::withoutGlobalScopes()->whereIn('call_sid', $sids)->pluck('call_sid')->all(),
        ]));
    }
}
