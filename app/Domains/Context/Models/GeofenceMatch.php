<?php

namespace App\Domains\Context\Models;

use App\Domains\Context\Enums\GeofenceMatchType;
use App\Domains\Normalization\Models\NormalizedEvent;
use Database\Factories\Domains\Context\GeofenceMatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeofenceMatch extends Model
{
    /** @use HasFactory<GeofenceMatchFactory> */
    use HasFactory;

    protected $fillable = [
        'normalized_event_id',
        'geofence_id',
        'match_type',
        'matched_at',
        'distance_meters',
        'metadata_json',
    ];

    /**
     * @return BelongsTo<NormalizedEvent, $this>
     */
    public function normalizedEvent(): BelongsTo
    {
        return $this->belongsTo(NormalizedEvent::class, 'normalized_event_id');
    }

    /**
     * @return BelongsTo<Geofence, $this>
     */
    public function geofence(): BelongsTo
    {
        return $this->belongsTo(Geofence::class, 'geofence_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'match_type' => GeofenceMatchType::class,
            'matched_at' => 'datetime',
            'distance_meters' => 'integer',
            'metadata_json' => 'array',
        ];
    }

    protected static function newFactory(): GeofenceMatchFactory
    {
        return GeofenceMatchFactory::new();
    }
}
