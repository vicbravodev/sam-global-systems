<?php

namespace App\Domains\Tenancy\Models;

use App\Concerns\BelongsToTenant;
use App\Models\User;
use Database\Factories\Domains\Tenancy\UsageEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageEvent extends Model
{
    /** @use HasFactory<UsageEventFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'team_id',
        'user_id',
        'usage_meter_id',
        'event_key',
        'quantity',
        'metadata_json',
        'occurred_at',
        'billing_period_key',
    ];

    /**
     * @return BelongsTo<UsageMeter, $this>
     */
    public function usageMeter(): BelongsTo
    {
        return $this->belongsTo(UsageMeter::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'metadata_json' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function newFactory(): UsageEventFactory
    {
        return UsageEventFactory::new();
    }
}
