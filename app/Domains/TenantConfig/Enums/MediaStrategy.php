<?php

namespace App\Domains\TenantConfig\Enums;

enum MediaStrategy: string
{
    case Optional = 'optional';
    case Preferred = 'preferred';
    case RequiredForCritical = 'required_for_critical';
    case WaitBeforeDeciding = 'wait_before_deciding';

    /**
     * Whether the AI module should defer evaluation until media has arrived.
     */
    public function blocksEvaluation(): bool
    {
        return $this === self::WaitBeforeDeciding;
    }
}
