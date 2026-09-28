<?php

namespace App\Domains\Tenancy\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\Domains\Tenancy\TenantBillingTermsFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Términos comerciales explícitos de un tenant. Null en una columna = usar
 * el default de plataforma (`config/billing.php`); ver ResolveBillingTerms.
 */
class TenantBillingTerms extends Model
{
    /** @use HasFactory<TenantBillingTermsFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'tenant_billing_terms';

    protected $fillable = [
        'team_id',
        'unit_price',
        'currency',
        'included_assets',
        'min_billable_assets',
        'ai_fair_use_per_asset',
        'ai_overage_unit_price',
        'messaging_markup_percent',
        'fx_usd_rate',
        'volume_tiers_json',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'included_assets' => 'integer',
            'min_billable_assets' => 'integer',
            'ai_fair_use_per_asset' => 'integer',
            'ai_overage_unit_price' => 'decimal:4',
            'messaging_markup_percent' => 'decimal:2',
            'fx_usd_rate' => 'decimal:4',
            'volume_tiers_json' => 'array',
        ];
    }

    protected static function newFactory(): TenantBillingTermsFactory
    {
        return TenantBillingTermsFactory::new();
    }
}
