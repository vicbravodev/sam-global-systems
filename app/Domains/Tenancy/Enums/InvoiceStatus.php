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
            self::Draft => 'Borrador',
            self::Finalized => 'Finalizada',
            self::Invoiced => 'Facturada',
            self::Paid => 'Pagada',
            self::Disputed => 'En disputa',
            self::Void => 'Anulada',
        };
    }
}
