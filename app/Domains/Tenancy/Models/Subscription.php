<?php

namespace App\Domains\Tenancy\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Enums\BillingCycle;
use App\Domains\Tenancy\Enums\SubscriptionStatus;
use Database\Factories\Domains\Tenancy\SubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'team_subscriptions';

    protected $fillable = [
        'team_id',
        'plan_id',
        'status',
        'billing_cycle',
        'starts_at',
        'renews_at',
        'ends_at',
        'trial_ends_at',
        'cancel_at_period_end',
        'external_provider',
        'external_subscription_id',
        'external_customer_id',
    ];

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isActive(): bool
    {
        return $this->status->grantsOperationalAccess();
    }

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'billing_cycle' => BillingCycle::class,
            'starts_at' => 'datetime',
            'renews_at' => 'datetime',
            'ends_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
        ];
    }

    protected static function newFactory(): SubscriptionFactory
    {
        return SubscriptionFactory::new();
    }
}
