<?php

namespace App\Domains\Drivers\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Assets\Models\Asset;
use App\Domains\Drivers\Enums\HosEpisodeResolution;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Incidents\Models\Incident;
use Database\Factories\Domains\Drivers\HosEpisodeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One HOS situation of one driver, from detection until it is corrected,
 * expires or the driver leaves the monitored set. An episode whose reminder
 * ladder escalated keeps open (`escalated_at`) until the driver corrects.
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
        'escalated_at',
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
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * Stores the incident this episode escalated into. Never one of another
     * tenant: the incident is found through ids, so the team is re-checked
     * at the only place that writes the column.
     */
    public function attachIncident(Incident $incident): void
    {
        if ($incident->team_id !== $this->team_id) {
            throw new LogicException('An HOS episode can only point to an incident of its own team.');
        }

        $this->forceFill(['incident_id' => $incident->id])->save();
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
            'escalated_at' => 'datetime',
            'ladder_step' => 'integer',
            'snapshot_json' => 'array',
        ];
    }

    protected static function newFactory(): HosEpisodeFactory
    {
        return HosEpisodeFactory::new();
    }
}
