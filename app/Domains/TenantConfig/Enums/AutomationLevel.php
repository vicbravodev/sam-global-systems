<?php

namespace App\Domains\TenantConfig\Enums;

use App\Contracts\HasLabel;

enum AutomationLevel: string implements HasLabel
{
    case Conservative = 'conservative';
    case Assisted = 'assisted';
    case SemiAutomatic = 'semi_automatic';
    case HighlyAutomated = 'highly_automated';

    public function label(): string
    {
        return match ($this) {
            self::Conservative => 'Conservador',
            self::Assisted => 'Asistido',
            self::SemiAutomatic => 'Semiautomático',
            self::HighlyAutomated => 'Altamente automatizado',
        };
    }

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
