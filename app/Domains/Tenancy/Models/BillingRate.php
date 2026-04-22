<?php

namespace App\Domains\Tenancy\Models;

use App\Domains\Tenancy\Enums\BillingModel;
use Database\Factories\Domains\Tenancy\BillingRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingRate extends Model
{
    /** @use HasFactory<BillingRateFactory> */
    use HasFactory;

    protected $fillable = [
        'plan_id',
        'usage_meter_id',
        'included_quantity',
        'overage_unit_price',
        'billing_model',
        'tiers_json',
    ];

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return BelongsTo<UsageMeter, $this>
     */
    public function usageMeter(): BelongsTo
    {
        return $this->belongsTo(UsageMeter::class);
    }

    protected function casts(): array
    {
        return [
            'included_quantity' => 'integer',
            'overage_unit_price' => 'decimal:4',
            'billing_model' => BillingModel::class,
            'tiers_json' => 'array',
        ];
    }

    protected static function newFactory(): BillingRateFactory
    {
        return BillingRateFactory::new();
    }
}
