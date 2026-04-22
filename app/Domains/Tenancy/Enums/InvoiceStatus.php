<?php

namespace App\Domains\Tenancy\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Finalized = 'finalized';
    case Invoiced = 'invoiced';
    case Paid = 'paid';
    case Disputed = 'disputed';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Finalized => 'Finalized',
            self::Invoiced => 'Invoiced',
            self::Paid => 'Paid',
            self::Disputed => 'Disputed',
            self::Void => 'Void',
        };
    }
}
