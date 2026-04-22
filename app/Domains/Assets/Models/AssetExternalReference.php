<?php

namespace App\Domains\Assets\Models;

use App\Domains\Integrations\Models\IntegrationProvider;
use Database\Factories\Domains\Assets\AssetExternalReferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetExternalReference extends Model
{
    /** @use HasFactory<AssetExternalReferenceFactory> */
    use HasFactory;

    protected $fillable = [
        'asset_id',
        'provider_id',
        'external_id',
        'external_type',
        'metadata_json',
        'first_seen_at',
        'last_seen_at',
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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata_json' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    protected static function newFactory(): AssetExternalReferenceFactory
    {
        return AssetExternalReferenceFactory::new();
    }
}
