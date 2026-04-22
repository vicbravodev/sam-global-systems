<?php

namespace App\Domains\Ingestion\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Ingestion\Enums\EventSourceStatus;
use App\Domains\Ingestion\Enums\EventSourceType;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use Database\Factories\Domains\Ingestion\EventSourceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventSource extends Model
{
    /** @use HasFactory<EventSourceFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'team_id',
        'provider_id',
        'tenant_integration_id',
        'source_type',
        'source_name',
        'status',
        'config_json',
    ];

    /**
     * @return BelongsTo<IntegrationProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(IntegrationProvider::class, 'provider_id');
    }

    /**
     * @return BelongsTo<TenantIntegration, $this>
     */
    public function tenantIntegration(): BelongsTo
    {
        return $this->belongsTo(TenantIntegration::class, 'tenant_integration_id');
    }

    /**
     * @return HasMany<RawEvent, $this>
     */
    public function rawEvents(): HasMany
    {
        return $this->hasMany(RawEvent::class, 'event_source_id');
    }

    public function isActive(): bool
    {
        return $this->status === EventSourceStatus::Active;
    }

    /**
     * @param  Builder<EventSource>  $query
     * @return Builder<EventSource>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', EventSourceStatus::Active);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_type' => EventSourceType::class,
            'status' => EventSourceStatus::class,
            'config_json' => 'array',
        ];
    }

    protected static function newFactory(): EventSourceFactory
    {
        return EventSourceFactory::new();
    }
}
