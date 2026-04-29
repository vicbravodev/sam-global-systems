<?php

namespace App\Domains\Incidents\Models;

use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use Database\Factories\Domains\Incidents\IncidentTimelineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentTimeline extends Model
{
    /** @use HasFactory<IncidentTimelineFactory> */
    use HasFactory;

    protected $fillable = [
        'incident_id',
        'entry_type',
        'actor_type',
        'actor_id',
        'title',
        'description',
        'payload_json',
        'occurred_at',
    ];

    /**
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_type' => TimelineEntryType::class,
            'actor_type' => TimelineActorType::class,
            'payload_json' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function newFactory(): IncidentTimelineFactory
    {
        return IncidentTimelineFactory::new();
    }
}
