<?php

namespace App\Domains\Tenancy\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\Domains\Tenancy\TenantUsageCounterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantUsageCounter extends Model
{
    /** @use HasFactory<TenantUsageCounterFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'team_id',
        'usage_meter_id',
        'period_start',
        'period_end',
        'consumed_value',
        'included_value',
        'overage_value',
        'last_calculated_at',
    ];

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
            'period_start' => 'date',
            'period_end' => 'date',
            'consumed_value' => 'integer',
            'included_value' => 'integer',
            'overage_value' => 'integer',
            'last_calculated_at' => 'datetime',
        ];
    }

    protected static function newFactory(): TenantUsageCounterFactory
    {
        return TenantUsageCounterFactory::new();
    }
}
