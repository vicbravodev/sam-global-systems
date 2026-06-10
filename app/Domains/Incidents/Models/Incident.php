<?php

namespace App\Domains\Incidents\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Assets\Models\Asset;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\IncidentSourceType;
use App\Domains\Normalization\Models\NormalizedEvent;
use Database\Factories\Domains\Incidents\IncidentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Incident extends Model
{
    /** @use HasFactory<IncidentFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'team_id',
        'incident_type_id',
        'incident_status_id',
        'incident_priority_id',
        'source_type',
        'source_reference_id',
        'related_event_id',
        'related_decision_id',
        'asset_id',
        'driver_id',
        'title',
        'summary',
        'description',
        'opened_at',
        'sla_due_at',
        'acknowledged_at',
        'acknowledged_by',
        'resolved_at',
        'closed_at',
        'false_positive_at',
        'cancelled_at',
        'external_resolved_at',
        'created_by_type',
        'created_by_id',
        'metadata_json',
    ];

    /**
     * @return BelongsTo<IncidentType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(IncidentType::class, 'incident_type_id');
    }

    /**
     * @return BelongsTo<IncidentStatus, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(IncidentStatus::class, 'incident_status_id');
    }

    /**
     * @return BelongsTo<IncidentPriority, $this>
     */
    public function priority(): BelongsTo
    {
        return $this->belongsTo(IncidentPriority::class, 'incident_priority_id');
    }

    /**
     * @return BelongsTo<NormalizedEvent, $this>
     */
    public function relatedEvent(): BelongsTo
    {
        return $this->belongsTo(NormalizedEvent::class, 'related_event_id');
    }

    /**
     * The AI evaluation that scored the event this incident originated from.
     *
     * Matches the incident's related normalized event to the evaluation that
     * scored it, so the inbox can surface AI confidence/decision/reasoning.
     *
     * @return HasOne<AIEventEvaluation, $this>
     */
    public function aiEvaluation(): HasOne
    {
        return $this->hasOne(AIEventEvaluation::class, 'normalized_event_id', 'related_event_id')
            ->latestOfMany();
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
     * @return HasMany<IncidentTimeline, $this>
     */
    public function timeline(): HasMany
    {
        return $this->hasMany(IncidentTimeline::class)->orderBy('occurred_at');
    }

    /**
     * @return HasMany<IncidentEvidence, $this>
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(IncidentEvidence::class);
    }

    /**
     * @return HasMany<IncidentAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(IncidentAssignment::class);
    }

    /**
     * @return HasOne<IncidentAssignment, $this>
     */
    public function currentAssignment(): HasOne
    {
        return $this->hasOne(IncidentAssignment::class)
            ->whereNull('unassigned_at')
            ->latestOfMany('assigned_at');
    }

    /**
     * @return HasMany<IncidentComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(IncidentComment::class);
    }

    /**
     * @return HasMany<IncidentEventLink, $this>
     */
    public function eventLinks(): HasMany
    {
        return $this->hasMany(IncidentEventLink::class);
    }

    /**
     * @return HasOne<IncidentResolution, $this>
     */
    public function resolution(): HasOne
    {
        return $this->hasOne(IncidentResolution::class);
    }

    public function isTerminal(): bool
    {
        $status = $this->relationLoaded('status') ? $this->status : $this->status()->first();

        return $status !== null && (bool) $status->is_terminal;
    }

    /**
     * @param  Builder<Incident>  $query
     * @return Builder<Incident>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereHas('status', fn (Builder $q) => $q->where('is_terminal', false));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_type' => IncidentSourceType::class,
            'created_by_type' => IncidentCreatorType::class,
            'metadata_json' => 'array',
            'opened_at' => 'datetime',
            'sla_due_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'false_positive_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'external_resolved_at' => 'datetime',
        ];
    }

    protected static function newFactory(): IncidentFactory
    {
        return IncidentFactory::new();
    }
}
