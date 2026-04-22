<?php

namespace App\Domains\Assets\Models;

use App\Domains\Assets\Enums\DeviceStatus;
use App\Domains\Integrations\Models\IntegrationProvider;
use Database\Factories\Domains\Assets\AssetDeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetDevice extends Model
{
    /** @use HasFactory<AssetDeviceFactory> */
    use HasFactory;

    protected $fillable = [
        'asset_id',
        'device_type',
        'provider_id',
        'external_device_id',
        'status',
        'attached_at',
        'detached_at',
        'metadata_json',
    ];

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return BelongsTo<IntegrationProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(IntegrationProvider::class, 'provider_id');
    }

    public function isAttached(): bool
    {
        return $this->status !== DeviceStatus::Detached && $this->detached_at === null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeviceStatus::class,
            'attached_at' => 'datetime',
            'detached_at' => 'datetime',
            'metadata_json' => 'array',
        ];
    }

    protected static function newFactory(): AssetDeviceFactory
    {
        return AssetDeviceFactory::new();
    }
}
