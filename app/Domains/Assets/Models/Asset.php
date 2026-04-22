<?php

namespace App\Domains\Assets\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
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
        'metadata_json',
        'first_seen_at',
        'last_seen_at',
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
        return $this->hasOne(AssetLocationSnapshot::class)->latestOfMany('recorded_at');
    }

    /**
     * @return HasMany<AssetExternalReference, $this>
     */
    public function externalReferences(): HasMany
    {
        return $this->hasMany(AssetExternalReference::class);
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AssetStatus::class,
            'metadata_json' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    protected static function newFactory(): AssetFactory
    {
        return AssetFactory::new();
    }
}
