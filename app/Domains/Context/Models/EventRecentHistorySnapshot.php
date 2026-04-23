<?php

namespace App\Domains\Context\Models;

use App\Domains\Normalization\Models\NormalizedEvent;
use Database\Factories\Domains\Context\EventRecentHistorySnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventRecentHistorySnapshot extends Model
{
    /** @use HasFactory<EventRecentHistorySnapshotFactory> */
    use HasFactory;

    protected $fillable = [
        'normalized_event_id',
        'window_start',
        'window_end',
        'recent_events_count',
        'recent_incidents_count',
        'recent_same_type_count',
        'recent_high_severity_count',
        'recent_locations_json',
        'recent_flags_json',
    ];

    /**
     * @return BelongsTo<NormalizedEvent, $this>
     */
    public function normalizedEvent(): BelongsTo
    {
        return $this->belongsTo(NormalizedEvent::class, 'normalized_event_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'window_start' => 'datetime',
            'window_end' => 'datetime',
            'recent_events_count' => 'integer',
            'recent_incidents_count' => 'integer',
            'recent_same_type_count' => 'integer',
            'recent_high_severity_count' => 'integer',
            'recent_locations_json' => 'array',
            'recent_flags_json' => 'array',
        ];
    }

    protected static function newFactory(): EventRecentHistorySnapshotFactory
    {
        return EventRecentHistorySnapshotFactory::new();
    }
}
