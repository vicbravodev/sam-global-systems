<?php

namespace App\Domains\Decisions\Enums;

enum DecisionOutcomeCode: string
{
    case Ignore = 'IGNORE';
    case LogOnly = 'LOG_ONLY';
    case Alert = 'ALERT';
    case Incident = 'INCIDENT';
    case Escalate = 'ESCALATE';
    case RequireHumanReview = 'REQUIRE_HUMAN_REVIEW';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Ignore, self::LogOnly => true,
            default => false,
        };
    }

    public function createsIncident(): bool
    {
        return match ($this) {
            self::Incident, self::Escalate => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Ignore => 'Ignorar',
            self::LogOnly => 'Solo registro',
            self::Alert => 'Alerta',
            self::Incident => 'Incidente',
            self::Escalate => 'Escalar',
            self::RequireHumanReview => 'Revisión humana',
        };
    }
}
