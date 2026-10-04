<?php

namespace App\Domains\Normalization\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Assets\Models\Asset;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Normalization\Enums\NormalizedEventStatus;
use Database\Factories\Domains\Normalization\NormalizedEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NormalizedEvent extends Model
{
    /** @use HasFactory<NormalizedEventFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'raw_event_id',
        'provider_event_key',
        'team_id',
        'trace_id',
        'provider_id',
        'asset_id',
        'driver_id',
        'event_type_id',
        'event_category_id',
        'event_severity_id',
        'occurred_at',
        'processed_at',
        'payload_normalized_json',
        'context_json',
        'status',
        'provider_state',
        'provider_updated_at',
        'provider_dismissed_at',
    ];

    /** Fila vieja de un evento del proveedor reemplazada por la entidad. */
    public const string PROVIDER_STATE_SUPERSEDED = 'superseded';

    /**
     * @return BelongsTo<RawEvent, $this>
     */
    public function rawEvent(): BelongsTo
    {
        return $this->belongsTo(RawEvent::class, 'raw_event_id');
    }

    /**
     * @return BelongsTo<IntegrationProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(IntegrationProvider::class, 'provider_id');
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
     * @return BelongsTo<EventType, $this>
     */
    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class, 'event_type_id');
    }

    /**
     * @return BelongsTo<EventCategory, $this>
     */
    public function eventCategory(): BelongsTo
    {
        return $this->belongsTo(EventCategory::class, 'event_category_id');
    }

    /**
     * @return BelongsTo<EventSeverity, $this>
     */
    public function eventSeverity(): BelongsTo
    {
        return $this->belongsTo(EventSeverity::class, 'event_severity_id');
    }

    /**
     * @param  Builder<NormalizedEvent>  $query
     * @return Builder<NormalizedEvent>
     */
    public function scopeWithStatus(Builder $query, NormalizedEventStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * @param  Builder<NormalizedEvent>  $query
     * @return Builder<NormalizedEvent>
     */
    public function scopeUnmapped(Builder $query): Builder
    {
        return $query->where('status', NormalizedEventStatus::Unmapped);
    }

    /**
     * Eventos que cuentan como comportamiento: fuera los descartados en origen
     * (falsos positivos) y las filas viejas reemplazadas por la entidad del
     * mismo evento. Lo usan el riesgo del conductor y el historial reciente.
     *
     * @param  Builder<NormalizedEvent>  $query
     * @return Builder<NormalizedEvent>
     */
    public function scopeCountable(Builder $query): Builder
    {
        return $query
            ->whereNull($query->qualifyColumn('provider_dismissed_at'))
            ->where(fn (Builder $q) => $q
                ->whereNull($q->qualifyColumn('provider_state'))
                ->orWhere($q->qualifyColumn('provider_state'), '!=', self::PROVIDER_STATE_SUPERSEDED));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => NormalizedEventStatus::class,
            'payload_normalized_json' => 'array',
            'context_json' => 'array',
            'occurred_at' => 'datetime',
            'processed_at' => 'datetime',
            'provider_updated_at' => 'datetime',
            'provider_dismissed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): NormalizedEventFactory
    {
        return NormalizedEventFactory::new();
    }
}
