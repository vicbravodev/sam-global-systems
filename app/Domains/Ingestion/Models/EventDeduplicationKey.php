<?php

namespace App\Domains\Ingestion\Models;

use Database\Factories\Domains\Ingestion\EventDeduplicationKeyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventDeduplicationKey extends Model
{
    /** @use HasFactory<EventDeduplicationKeyFactory> */
    use HasFactory;

    protected $fillable = [
        'team_id',
        'event_source_id',
        'deduplication_key',
        'raw_event_id',
        'first_seen_at',
        'expires_at',
    ];

    /**
     * @return BelongsTo<EventSource, $this>
     */
    public function eventSource(): BelongsTo
    {
        return $this->belongsTo(EventSource::class, 'event_source_id');
    }

    /**
     * @return BelongsTo<RawEvent, $this>
     */
    public function rawEvent(): BelongsTo
    {
        return $this->belongsTo(RawEvent::class, 'raw_event_id');
    }

    /**
     * @param  Builder<EventDeduplicationKey>  $query
     * @return Builder<EventDeduplicationKey>
     */
    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function newFactory(): EventDeduplicationKeyFactory
    {
        return EventDeduplicationKeyFactory::new();
    }
}
