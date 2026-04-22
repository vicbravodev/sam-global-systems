<?php

namespace App\Domains\Tenancy\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Enums\FeatureSource;
use Database\Factories\Domains\Tenancy\TenantFeatureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantFeature extends Model
{
    /** @use HasFactory<TenantFeatureFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'team_id',
        'feature_key',
        'enabled',
        'source',
        'limits_json',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'source' => FeatureSource::class,
            'limits_json' => 'array',
        ];
    }

    protected static function newFactory(): TenantFeatureFactory
    {
        return TenantFeatureFactory::new();
    }
}
