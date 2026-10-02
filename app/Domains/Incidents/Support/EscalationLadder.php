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
 *   entre ambos pasos (mínimo 1 minuto).
 * - Sin siguiente nivel: null (la escalera se agotó).
 */
final class EscalationLadder
{
    public const int DEFAULT_RETRY_MINUTES = 5;

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @return array{level: int, attempt: int, delay_minutes: int, mode: 'retry_same_level'|'next_level', calc: array<string, int>}|null
     */
    public static function next(array $steps, int $level, int $attempt): ?array
    {
        $step = $steps[$level] ?? null;
        $stepAttempts = max(1, (int) ($step['attempts'] ?? 1));

        if ($attempt < $stepAttempts) {
            $retryMinutes = max(1, (int) ($step['retry_minutes'] ?? self::DEFAULT_RETRY_MINUTES));

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
            return null;
        }

        $currentOffset = (int) ($step['delay_minutes'] ?? 0);
        $nextOffset = (int) ($next['delay_minutes'] ?? 0);

        return [
            'level' => $level + 1,
            'attempt' => 1,
            'delay_minutes' => max(1, $nextOffset - $currentOffset),
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
