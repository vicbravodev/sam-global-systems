<?php

namespace App\Domains\Integrations\Enums;

enum TenantIntegrationStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Error = 'error';
    case Pending = 'pending';

    /**
     * Maps the persisted status to the UI health vocabulary (drives the
     * bg-health-* dots on the dashboard and integrations pages).
     */
    public function healthKey(): string
    {
        return match ($this) {
            self::Active => 'ok',
            self::Pending => 'warn',
            self::Error => 'down',
            self::Inactive => 'unknown',
        };
    }
}
