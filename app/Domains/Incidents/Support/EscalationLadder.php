<?php

namespace App\Domains\Incidents\Support;

/**
 * Aritmética de la escalera de SLA (`TenantEscalationConfig.steps_json`):
 * dado el paso que acaba de dispararse, cuál sigue y cuándo. La comparten el
 * watchdog (CheckIncidentAcknowledgementJob) y la aceleración de una
 * emergencia (ArmIncidentEscalation::accelerate), que necesitan la misma
 * respuesta para no desincronizar el estado persistido.
 *
 * - Mientras el paso tenga `attempts` por gastar, se repite tras
 *   `retry_minutes` (por defecto 5).
 * - Después se pasa al siguiente nivel tras la diferencia de `delay_minutes`
 *   entre ambos pasos.
 * - Nunca menos de MIN_GAP_MINUTES entre dos pasos.
 * - Tras el último nivel: un paso de comprobación (exhaustionLevel) a
 *   EXHAUSTION_GRACE_MINUTES; si al llegar nadie atendió, se agota.
 * - Desde ese paso: null.
 */
final class EscalationLadder
{
    public const int DEFAULT_RETRY_MINUTES = 5;

    /**
     * Separación mínima entre dos pasos: mayor que la ventana anti-ruido de
     * canales pagados (DispatchNotification::PAID_COOLDOWN_SECONDS), para que
     * un re-aviso deliberado de la escalera nunca se tome por ruido.
     */
    public const int MIN_GAP_MINUTES = 2;

    /**
     * Tiempo que se le da al último nivel para atender antes de declarar la
     * escalera agotada (y avisar a SAM).
     */
    public const int EXHAUSTION_GRACE_MINUTES = 5;

    /**
     * Nivel "virtual" tras el último: el paso que sólo comprueba si alguien
     * atendió y, si no, agota la escalera. Sin pasos configurados el nivel 0
     * (por defecto) sigue existiendo.
     *
     * @param  array<int, array<string, mixed>>  $steps
     */
    public static function exhaustionLevel(array $steps): int
    {
        return max(1, count($steps));
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @return array{level: int, attempt: int, delay_minutes: int, mode: 'retry_same_level'|'next_level'|'exhaustion_check', calc: array<string, int>}|null
     */
    public static function next(array $steps, int $level, int $attempt): ?array
    {
        $step = $steps[$level] ?? null;
        $stepAttempts = max(1, (int) ($step['attempts'] ?? 1));

        if ($attempt < $stepAttempts) {
            $retryMinutes = max(self::MIN_GAP_MINUTES, (int) ($step['retry_minutes'] ?? self::DEFAULT_RETRY_MINUTES));

            return [
                'level' => $level,
                'attempt' => $attempt + 1,
                'delay_minutes' => $retryMinutes,
                'mode' => 'retry_same_level',
                'calc' => [
                    'step_attempts' => $stepAttempts,
                    'retry_minutes' => $retryMinutes,
                    'default_retry_minutes' => self::DEFAULT_RETRY_MINUTES,
                ],
            ];
        }

        $next = $steps[$level + 1] ?? null;

        if ($next === null) {
            if ($level >= self::exhaustionLevel($steps)) {
                return null;
            }

            return [
                'level' => self::exhaustionLevel($steps),
                'attempt' => 1,
                'delay_minutes' => self::EXHAUSTION_GRACE_MINUTES,
                'mode' => 'exhaustion_check',
                'calc' => [
                    'step_attempts' => $stepAttempts,
                    'exhaustion_grace_minutes' => self::EXHAUSTION_GRACE_MINUTES,
                ],
            ];
        }

        $currentOffset = (int) ($step['delay_minutes'] ?? 0);
        $nextOffset = (int) ($next['delay_minutes'] ?? 0);

        return [
            'level' => $level + 1,
            'attempt' => 1,
            'delay_minutes' => max(self::MIN_GAP_MINUTES, $nextOffset - $currentOffset),
            'mode' => 'next_level',
            'calc' => [
                'step_attempts' => $stepAttempts,
                'current_offset_minutes' => $currentOffset,
                'next_offset_minutes' => $nextOffset,
            ],
        ];
    }

    /**
     * Orden lexicográfico (nivel, intento): true si `a` va antes que `b`.
     */
    public static function precedes(int $levelA, int $attemptA, int $levelB, int $attemptB): bool
    {
        return $levelA < $levelB || ($levelA === $levelB && $attemptA < $attemptB);
    }
}
