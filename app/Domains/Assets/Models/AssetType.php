<?php

namespace App\Domains\Assets\Models;

use App\Domains\Assets\Enums\AssetCategory;
use Database\Factories\Domains\Assets\AssetTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetType extends Model
{
    /** @use HasFactory<AssetTypeFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'category',
        'capabilities_json',
    ];

    /**
     * @return HasMany<Asset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => AssetCategory::class,
            'capabilities_json' => 'array',
        ];
    }

    protected static function newFactory(): AssetTypeFactory
    {
        return AssetTypeFactory::new();
    }
}
