<?php

namespace App\Domains\Drivers\Support;

use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Models\HosDriverState;

/**
 * Qué tan urgente es un chofer vigilado para la vista de flota (spec §3.12):
 * infracción > en el límite (trabajando con un reloj agotado, o escalera ya
 * escalada) > con un aviso abierto > en regla. Dentro de cada nivel va
 * primero quien trabaja con menos tiempo en algún reloj; quien está parado
 * no compite por tiempo (un manejo en 0 mientras descansa es lo normal).
 */
final class HosUrgency
{
    public const string VIOLATION = 'violation';

    public const string AT_LIMIT = 'at_limit';

    public const string WARNING = 'warning';

    public const string OK = 'ok';

    /** @var array<string, int> */
    private const array RANK = [self::VIOLATION => 0, self::AT_LIMIT => 1, self::WARNING => 2, self::OK => 3];

    /**
     * @param  list<HosSituation>  $openSituations
     */
    public static function level(?HosDriverState $state, array $openSituations, bool $escalated): string
    {
        if (in_array(HosSituation::Violation, $openSituations, true) || ($state !== null && $state->violation_s > 0)) {
            return self::VIOLATION;
        }

        $remaining = self::sortSeconds($state);

        if ($escalated || ($remaining !== null && $remaining <= 0)) {
            return self::AT_LIMIT;
        }

        $warnings = array_filter($openSituations, fn (HosSituation $situation): bool => $situation !== HosSituation::RestComplete);

        return $warnings === [] ? self::OK : self::WARNING;
    }

    /** El reloj más corto (descanso, manejo, turno o ciclo); null sin datos. */
    public static function minRemaining(?HosDriverState $state): ?int
    {
        if ($state === null) {
            return null;
        }

        $clocks = array_filter($state->clockSnapshot(), fn (?int $seconds): bool => $seconds !== null);

        return $clocks === [] ? null : min($clocks);
    }

    /** El reloj más corto sólo si está trabajando con la app conectada. */
    public static function sortSeconds(?HosDriverState $state): ?int
    {
        if ($state === null || $state->app_disconnected_since !== null || $state->duty_status?->isWorking() !== true) {
            return null;
        }

        return self::minRemaining($state);
    }

    public static function rank(string $level): int
    {
        return self::RANK[$level] ?? count(self::RANK);
    }
}
