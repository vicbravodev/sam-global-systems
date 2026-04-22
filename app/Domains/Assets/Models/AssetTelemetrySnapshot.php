<?php

namespace App\Domains\Assets\Models;

use App\Domains\Assets\Enums\TelemetryType;
use Database\Factories\Domains\Assets\AssetTelemetrySnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetTelemetrySnapshot extends Model
{
    /** @use HasFactory<AssetTelemetrySnapshotFactory> */
    use HasFactory;

    protected $fillable = [
        'asset_id',
        'telemetry_type',
        'data_json',
        'recorded_at',
        'source_event_id',
    ];

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'telemetry_type' => TelemetryType::class,
            'data_json' => 'array',
            'recorded_at' => 'datetime',
        ];
    }

    protected static function newFactory(): AssetTelemetrySnapshotFactory
    {
        return AssetTelemetrySnapshotFactory::new();
    }
}
