<?php

namespace App\Domains\Assets\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Assets\Enums\AssetMonitoringState;
use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Enums\TelemetryType;
use App\Domains\Drivers\Enums\AssignmentType;
use App\Domains\Drivers\Models\DriverAssignment;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use Carbon\CarbonInterface;
use Database\Factories\Domains\Assets\AssetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'team_id',
        'asset_type_id',
        'provider_id',
        'source_integration_id',
        'external_primary_id',
        'name',
        'code',
        'status',
        'monitoring_state',
        'monitoring_changed_at',
        'metadata_json',
        'first_seen_at',
        'last_seen_at',
        'device_last_connected_at',
        'device_health_status',
        'device_connectivity_polled_at',
        'last_latitude',
        'last_longitude',
        'last_speed_kph',
        'last_heading',
        'last_formatted_location',
        'last_location_at',
        'last_moving_at',
        'stopped_since',
        'stop_alerted_for',
        'stop_latitude',
        'stop_longitude',
        'stop_alerted_latitude',
        'stop_alerted_longitude',
        'after_hours_alerted_at',
    ];

    /**
     * @return BelongsTo<AssetType, $this>
     */
    public function assetType(): BelongsTo
    {
        return $this->belongsTo(AssetType::class);
    }

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
    public function sourceIntegration(): BelongsTo
    {
        return $this->belongsTo(TenantIntegration::class, 'source_integration_id');
    }

    /**
     * @return HasMany<AssetDevice, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(AssetDevice::class);
    }

    /**
     * @return HasMany<AssetLocationSnapshot, $this>
     */
    public function locationSnapshots(): HasMany
    {
        return $this->hasMany(AssetLocationSnapshot::class);
    }

    /**
     * @return HasOne<AssetLocationSnapshot, $this>
     */
    public function latestLocation(): HasOne
    {
        // Bounded by the asset's own `last_location_at` (set by the telematics
        // feed from a point it has just stored, same whole-second UTC value).
        // Without it the one-of-many subquery computes MAX(recorded_at) by
        // reading EVERY retained GPS point of each asset (hundreds of
        // thousands per unit at feed rate); with it the index seeks straight
        // to the newest ones. Newer points written by other paths (sync, per
        // event) are still >= and still win; assets the feed never touched
        // (NULL) fall back to the full range.
        return $this->hasOne(AssetLocationSnapshot::class)->ofMany(
            ['recorded_at' => 'max', 'id' => 'max'],
            fn (Builder $query) => $query->join('assets as latest_location_asset', fn ($join) => $join
                ->on('latest_location_asset.id', '=', 'asset_location_snapshots.asset_id')
                ->whereRaw("asset_location_snapshots.recorded_at >= COALESCE(latest_location_asset.last_location_at, '1970-01-01 00:00:00')")),
            'latestLocation',
        );
    }

    /**
     * @return HasOne<AssetTelemetrySnapshot, $this>
     */
    public function latestTelemetry(): HasOne
    {
        return $this->hasOne(AssetTelemetrySnapshot::class)->latestOfMany('recorded_at');
    }

    /**
     * Newest speed reading recorded as telemetry (stats poller or a
     * speeding event's measured peak).
     *
     * @return HasOne<AssetTelemetrySnapshot, $this>
     */
    public function latestSpeedTelemetry(): HasOne
    {
        return $this->hasOne(AssetTelemetrySnapshot::class)->ofMany(
            ['recorded_at' => 'max', 'id' => 'max'],
            fn (Builder $query) => $query->where('telemetry_type', TelemetryType::Speed),
        );
    }

    /**
     * @return HasMany<AssetExternalReference, $this>
     */
    public function externalReferences(): HasMany
    {
        return $this->hasMany(AssetExternalReference::class);
    }

    /**
     * Active primary-driver assignment for this asset, if any. Mirrors
     * Driver::currentAssignment() from the other side of the relation so the
     * asset detail can surface who is currently operating it (C-08).
     *
     * @return HasOne<DriverAssignment, $this>
     */
    public function currentDriverAssignment(): HasOne
    {
        return $this->hasOne(DriverAssignment::class)
            ->where('assignment_type', AssignmentType::PrimaryDriver)
            ->whereNull('ended_at')
            ->latestOfMany('started_at');
    }

    /**
     * @return HasMany<AssetTelemetrySnapshot, $this>
     */
    public function telemetrySnapshots(): HasMany
    {
        return $this->hasMany(AssetTelemetrySnapshot::class);
    }

    /**
     * @param  Builder<Asset>  $query
     * @return Builder<Asset>
     */
    public function scopeWithStatus(Builder $query, AssetStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * @param  Builder<Asset>  $query
     * @return Builder<Asset>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '!=', AssetStatus::Inactive);
    }

    /**
     * Non-inactive assets that were part of the fleet during [from, to]: first
     * seen (or created) by the end of the window and not deleted before it
     * started. Status history is not kept, so the current status decides
     * whether the asset counts as active.
     *
     * @param  Builder<Asset>  $query
     * @return Builder<Asset>
     */
    public function scopeActiveDuring(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query
            ->withTrashed()
            ->where('status', '!=', AssetStatus::Inactive)
            ->whereRaw('COALESCE(first_seen_at, created_at) <= ?', [$to->toDateTimeString()])
            ->where(fn (Builder $deleted) => $deleted
                ->whereNull('deleted_at')
                ->orWhere('deleted_at', '>=', $from));
    }

    /**
     * @return array<string, string>
     */
    /**
     * Activos que SAM vigila: los únicos que se sondean, normalizan, evalúan
     * y facturan. Un activo `pending`/`excluded` existe en inventario pero el
     * pipeline lo ignora.
     *
     * @param  Builder<Asset>  $query
     * @return Builder<Asset>
     */
    public function scopeMonitored(Builder $query): Builder
    {
        return $query->where('monitoring_state', AssetMonitoringState::Monitored);
    }

    /**
     * Activos descubiertos por el sync que el cliente aún no enciende.
     *
     * @param  Builder<Asset>  $query
     * @return Builder<Asset>
     */
    public function scopePendingMonitoring(Builder $query): Builder
    {
        return $query->where('monitoring_state', AssetMonitoringState::Pending);
    }

    public function isMonitored(): bool
    {
        return $this->monitoring_state === AssetMonitoringState::Monitored;
    }

    protected function casts(): array
    {
        return [
            'status' => AssetStatus::class,
            'monitoring_state' => AssetMonitoringState::class,
            'monitoring_changed_at' => 'datetime',
            'metadata_json' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'device_last_connected_at' => 'datetime',
            'device_connectivity_polled_at' => 'datetime',
            'last_latitude' => 'float',
            'last_longitude' => 'float',
            'last_speed_kph' => 'float',
            'last_heading' => 'integer',
            'last_location_at' => 'datetime',
            'last_moving_at' => 'datetime',
            'stopped_since' => 'datetime',
            'stop_alerted_for' => 'datetime',
            'stop_latitude' => 'float',
            'stop_longitude' => 'float',
            'stop_alerted_latitude' => 'float',
            'stop_alerted_longitude' => 'float',
            'after_hours_alerted_at' => 'datetime',
        ];
    }

    protected static function newFactory(): AssetFactory
    {
        return AssetFactory::new();
    }
}
