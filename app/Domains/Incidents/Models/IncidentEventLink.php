<?php

namespace App\Domains\Incidents\Models;

use App\Domains\Incidents\Enums\EventRelationType;
use App\Domains\Normalization\Models\NormalizedEvent;
use Database\Factories\Domains\Incidents\IncidentEventLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentEventLink extends Model
{
    /** @use HasFactory<IncidentEventLinkFactory> */
    use HasFactory;

    protected $fillable = [
        'incident_id',
        'normalized_event_id',
        'relation_type',
    ];

    /**
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

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
            'relation_type' => EventRelationType::class,
        ];
    }

    protected static function newFactory(): IncidentEventLinkFactory
    {
        return IncidentEventLinkFactory::new();
    }
}
