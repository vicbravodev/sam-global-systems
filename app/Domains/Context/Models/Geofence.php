<?php

namespace App\Domains\Context\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Context\Enums\GeofenceCategory;
use App\Domains\Context\Enums\GeofenceType;
use Database\Factories\Domains\Context\GeofenceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Geofence extends Model
{
    /** @use HasFactory<GeofenceFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'team_id',
        'name',
        'code',
        'geofence_type',
        'geometry_json',
        'category',
        'is_active',
        'metadata_json',
    ];

    /**
     * @param  Builder<Geofence>  $query
     * @return Builder<Geofence>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'geofence_type' => GeofenceType::class,
            'category' => GeofenceCategory::class,
            'geometry_json' => 'array',
            'metadata_json' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): GeofenceFactory
    {
        return GeofenceFactory::new();
    }
}
