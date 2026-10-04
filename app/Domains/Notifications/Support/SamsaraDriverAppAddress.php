<?php

namespace App\Domains\Notifications\Support;

/**
 * Destino del canal `samsara_driver_app`: la integración Samsara del tenant
 * y el id del chofer en Samsara, como `samsara:{integration}:{chofer}`. El
 * driver resuelve la integración DENTRO del team de la entrega: una
 * dirección con el id de la integración de otro tenant no encuentra nada.
 */
final class SamsaraDriverAppAddress
{
    public static function make(int $integrationId, string $externalDriverId): string
    {
        return "samsara:{$integrationId}:{$externalDriverId}";
    }

    /**
     * @return array{integration_id: int, external_driver_id: string}|null
     */
    public static function parse(string $address): ?array
    {
        if (preg_match('/^samsara:(\d+):(\d+)$/', trim($address), $matches) !== 1) {
            return null;
        }

        return ['integration_id' => (int) $matches[1], 'external_driver_id' => $matches[2]];
    }
}
