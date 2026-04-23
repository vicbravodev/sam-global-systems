<?php

namespace App\Domains\Context\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Assets\Models\Asset;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Normalization\Models\NormalizedEvent;
use Database\Factories\Domains\Context\EventContextSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EventContextSnapshot extends Model
{
    /** @use HasFactory<EventContextSnapshotFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'normalized_event_id',
        'team_id',
        'asset_id',
        'driver_id',
        'event_occurred_at',
        'context_version',
        'location_snapshot_json',
        'asset_snapshot_json',
        'driver_snapshot_json',
        'telemetry_snapshot_json',
        'geofence_snapshot_json',
        'incidents_snapshot_json',
        'recent_history_snapshot_json',
        'media_snapshot_json',
        'signals_json',
    ];

    /**
     * @return BelongsTo<NormalizedEvent, $this>
     */
    public function normalizedEvent(): BelongsTo
    {
        return $this->belongsTo(NormalizedEvent::class, 'normalized_event_id');
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }

    /**
     * @return HasOne<OperationalContextProfile, $this>
     */
    public function profile(): HasOne
    {
        return $this->hasOne(OperationalContextProfile::class, 'normalized_event_id', 'normalized_event_id');
    }

    /**
     * @return HasMany<GeofenceMatch, $this>
     */
    public function geofenceMatches(): HasMany
    {
        return $this->hasMany(GeofenceMatch::class, 'normalized_event_id', 'normalized_event_id');
    }

    /**
     * @return HasOne<EventRecentHistorySnapshot, $this>
     */
    public function recentHistory(): HasOne
    {
        return $this->hasOne(EventRecentHistorySnapshot::class, 'normalized_event_id', 'normalized_event_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_occurred_at' => 'datetime',
            'context_version' => 'integer',
            'location_snapshot_json' => 'array',
            'asset_snapshot_json' => 'array',
            'driver_snapshot_json' => 'array',
            'telemetry_snapshot_json' => 'array',
            'geofence_snapshot_json' => 'array',
            'incidents_snapshot_json' => 'array',
            'recent_history_snapshot_json' => 'array',
            'media_snapshot_json' => 'array',
            'signals_json' => 'array',
        ];
    }

    protected static function newFactory(): EventContextSnapshotFactory
    {
        return EventContextSnapshotFactory::new();
    }
}
