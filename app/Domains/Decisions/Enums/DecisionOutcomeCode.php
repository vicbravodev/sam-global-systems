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

    /**
     * Outcomes that open a full incident at the decision's own priority. The
     * critical-severity floor in `ResolveDecisionOutcome` relies on this.
     */
    public function createsIncident(): bool
    {
        return match ($this) {
            self::Incident, self::Escalate => true,
            default => false,
        };
    }

    /**
     * Outcomes an operator must see in the inbox: full incidents plus review
     * and alert outcomes, which open low-urgency incidents so they are never
     * silently lost. IGNORE / LOG_ONLY surface nothing.
     */
    public function surfacesToOperators(): bool
    {
        return $this->createsIncident() || $this === self::RequireHumanReview || $this === self::Alert;
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
