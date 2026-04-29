<?php

namespace App\Domains\TenantConfig\Enums;

enum AutomationLevel: string
{
    case Conservative = 'conservative';
    case Assisted = 'assisted';
    case SemiAutomatic = 'semi_automatic';
    case HighlyAutomated = 'highly_automated';

    /**
     * Whether the AI module should request human review by default for
     * non-trivial decisions under this automation level.
     */
    public function requiresHumanReview(): bool
    {
        return match ($this) {
            self::Conservative, self::Assisted => true,
            self::SemiAutomatic, self::HighlyAutomated => false,
        };
    }
}
