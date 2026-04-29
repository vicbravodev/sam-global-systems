<?php

namespace App\Domains\Context\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Context\Enums\IncidentRelationType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Normalization\Models\NormalizedEvent;
use Database\Factories\Domains\Context\EventRelatedIncidentLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventRelatedIncidentLink extends Model
{
    /** @use HasFactory<EventRelatedIncidentLinkFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'team_id',
        'normalized_event_id',
        'incident_id',
        'relation_type',
        'confidence_score',
    ];

    /**
     * @return BelongsTo<NormalizedEvent, $this>
     */
    public function normalizedEvent(): BelongsTo
    {
        return $this->belongsTo(NormalizedEvent::class, 'normalized_event_id');
    }

    /**
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'incident_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'relation_type' => IncidentRelationType::class,
            'confidence_score' => 'decimal:2',
        ];
    }

    protected static function newFactory(): EventRelatedIncidentLinkFactory
    {
        return EventRelatedIncidentLinkFactory::new();
    }
}
