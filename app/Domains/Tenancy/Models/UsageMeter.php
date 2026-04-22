<?php

namespace App\Domains\Tenancy\Models;

use App\Domains\Tenancy\Enums\AggregationType;
use App\Domains\Tenancy\Enums\ResetPeriod;
use Database\Factories\Domains\Tenancy\UsageMeterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UsageMeter extends Model
{
    /** @use HasFactory<UsageMeterFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'unit',
        'aggregation_type',
        'is_billable',
        'reset_period',
        'provider_meter_event_name',
        'provider_meter_id',
    ];

    /**
     * @return HasMany<UsageEvent, $this>
     */
    public function usageEvents(): HasMany
    {
        return $this->hasMany(UsageEvent::class);
    }

    /**
     * @return HasMany<BillingRate, $this>
     */
    public function billingRates(): HasMany
    {
        return $this->hasMany(BillingRate::class);
    }

    /**
     * @return HasMany<UsageDailyAggregate, $this>
     */
    public function dailyAggregates(): HasMany
    {
        return $this->hasMany(UsageDailyAggregate::class);
    }

    protected function casts(): array
    {
        return [
            'aggregation_type' => AggregationType::class,
            'is_billable' => 'boolean',
            'reset_period' => ResetPeriod::class,
        ];
    }

    protected static function newFactory(): UsageMeterFactory
    {
        return UsageMeterFactory::new();
    }
}
