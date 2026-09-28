<?php

namespace App\Domains\Notifications\Enums;

enum NotificationPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Critical = 'critical';

    /**
     * Prioridad de la notificación a partir del código de prioridad del
     * incidente (`critical|high|medium|low`). Sólo un incidente crítico
     * produce una notificación crítica (la que abre SMS/voz por política).
     */
    public static function fromIncidentPriority(?string $incidentPriorityCode): self
    {
        return match ($incidentPriorityCode) {
            'critical' => self::Critical,
            'high' => self::High,
            'low' => self::Low,
            default => self::Normal,
        };
    }

    public function isCritical(): bool
    {
        return $this === self::Critical;
    }

    public function suppressedByMute(): bool
    {
        return $this === self::Low || $this === self::Normal;
    }
}
