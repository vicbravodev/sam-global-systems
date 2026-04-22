<?php

namespace App\Domains\Tenancy\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\Domains\Tenancy\UsageDailyAggregateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageDailyAggregate extends Model
{
    /** @use HasFactory<UsageDailyAggregateFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'team_id',
        'usage_meter_id',
        'day',
        'quantity_sum',
        'quantity_max',
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
            'day' => 'date',
            'quantity_sum' => 'integer',
            'quantity_max' => 'integer',
        ];
    }

    protected static function newFactory(): UsageDailyAggregateFactory
    {
        return UsageDailyAggregateFactory::new();
    }
}
