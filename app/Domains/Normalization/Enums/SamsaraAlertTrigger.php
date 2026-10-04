<?php

namespace App\Domains\Normalization\Enums;

/**
 * Disparadores de alertas de Samsara (`triggerTypeId` de una configuración de
 * alerta; llega como `data.conditions[].triggerId` en cada `AlertIncident`)
 * que SAM conoce por nombre. Los demás (geocercas, velocidad, fallas…) siguen
 * siendo legibles por su número: ver {@see classify()}.
 */
enum SamsaraAlertTrigger: int
{
    case PanicButton = 1034;
    case TamperingDetected = 1045;

    /** Ninguna condición trae un `triggerId`: no sabemos qué disparó la alerta. */
    public const string CLASS_UNREADABLE = 'unreadable';

    /** Alguna condición es de emergencia. */
    public const string CLASS_EMERGENCY = 'emergency';

    /** Disparadores legibles y ninguno de emergencia. */
    public const string CLASS_RECOGNIZED = 'recognized';

    public function isEmergency(): bool
    {
        return $this === self::PanicButton;
    }

    /**
     * `triggerId` enteros positivos de todas las condiciones, en orden. Acepta
     * cadenas numéricas; ignora lo demás.
     *
     * @param  array<array-key, mixed>  $payload  Payload del webhook (`data.conditions`).
     * @return list<int>
     */
    public static function fromPayload(array $payload): array
    {
        $conditions = data_get($payload, 'data.conditions');

        if (! is_array($conditions)) {
            return [];
        }

        $ids = [];

        foreach ($conditions as $condition) {
            $id = is_array($condition) ? ($condition['triggerId'] ?? null) : null;

            if (is_int($id) && $id > 0) {
                $ids[] = $id;
            } elseif (is_string($id) && ctype_digit($id) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * Qué se puede decir de una alerta sin regla por sus disparadores. Ante la
     * duda falla hacia la emergencia: sin ids legibles o con un pánico, se
     * trata como posible pánico.
     *
     * @param  list<int>  $triggerIds
     * @return self::CLASS_*
     */
    public static function classify(array $triggerIds): string
    {
        if ($triggerIds === []) {
            return self::CLASS_UNREADABLE;
        }

        foreach ($triggerIds as $id) {
            if (self::tryFrom($id)?->isEmergency() === true) {
                return self::CLASS_EMERGENCY;
            }
        }

        return self::CLASS_RECOGNIZED;
    }
}
