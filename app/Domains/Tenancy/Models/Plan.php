<?php

namespace App\Domains\Tenancy\Models;

use App\Domains\Tenancy\Enums\BillingCycle;
use Database\Factories\Domains\Tenancy\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'base_price',
        'currency',
        'billing_cycle',
        'is_active',
    ];

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * @return HasMany<BillingRate, $this>
     */
    public function billingRates(): HasMany
    {
        return $this->hasMany(BillingRate::class);
    }

    protected function casts(): array
    {
        return [
            'billing_cycle' => BillingCycle::class,
            'base_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): PlanFactory
    {
        return PlanFactory::new();
    }
}
