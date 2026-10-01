<?php

namespace App\Domains\Tenancy\Enums;

enum SubscriptionStatus: string
{
    case Active = 'active';
    case PastDue = 'past_due';
    case Suspended = 'suspended';
    case Canceled = 'canceled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activa',
            self::PastDue => 'Vencida',
            self::Suspended => 'Suspendida',
            self::Canceled => 'Cancelada',
            self::Expired => 'Expirada',
        };
    }

    public function grantsOperationalAccess(): bool
    {
        return in_array($this, [self::Active, self::PastDue], true);
    }

    public function grantsBillingAccess(): bool
    {
        return in_array($this, [self::Active, self::PastDue, self::Suspended], true);
    }
}
