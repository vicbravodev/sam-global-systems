<?php

namespace App\Domains\Assets\Models;

use App\Domains\Assets\Enums\LocationSource;
use Database\Factories\Domains\Assets\AssetLocationSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetLocationSnapshot extends Model
{
    /** @use HasFactory<AssetLocationSnapshotFactory> */
    use HasFactory;

    protected $fillable = [
        'asset_id',
        'latitude',
        'longitude',
        'formatted_location',
        'speed',
        'heading',
        'recorded_at',
        'source',
        'geocoding_metadata_json',
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
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'speed' => 'decimal:2',
            'recorded_at' => 'datetime',
            'source' => LocationSource::class,
            'geocoding_metadata_json' => 'array',
        ];
    }

    protected static function newFactory(): AssetLocationSnapshotFactory
    {
        return AssetLocationSnapshotFactory::new();
    }
}
