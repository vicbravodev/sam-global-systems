<?php

namespace App\Domains\Drivers\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Assets\Models\Asset;
use App\Domains\Drivers\Enums\HosEpisodeResolution;
use App\Domains\Drivers\Enums\HosSituation;
use Database\Factories\Domains\Drivers\HosEpisodeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One HOS situation of one driver, from detection until it is corrected,
 * expires, escalates to an incident or the driver leaves the monitored set.
 */
class HosEpisode extends Model
{
    /** @use HasFactory<HosEpisodeFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'team_id',
        'driver_id',
        'asset_id',
        'situation',
        'opened_at',
        'resolved_at',
        'resolution',
        'ladder_step',
        'next_nudge_at',
        'incident_id',
        'snapshot_json',
    ];

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @param  Builder<HosEpisode>  $query
     * @return Builder<HosEpisode>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'situation' => HosSituation::class,
            'resolution' => HosEpisodeResolution::class,
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
            'next_nudge_at' => 'datetime',
            'ladder_step' => 'integer',
            'snapshot_json' => 'array',
        ];
    }

    protected static function newFactory(): HosEpisodeFactory
    {
        return HosEpisodeFactory::new();
    }
}
