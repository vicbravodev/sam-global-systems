<?php

namespace App\Domains\TenantConfig\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\TenantConfig\Enums\SettingUpdatedByType;
use Database\Factories\Domains\TenantConfig\TenantConfigVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantConfigVersion extends Model
{
    /** @use HasFactory<TenantConfigVersionFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'tenant_config_versions';

    protected $fillable = [
        'team_id',
        'version',
        'snapshot_json',
        'created_by_type',
        'created_by_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'snapshot_json' => 'array',
            'created_by_type' => SettingUpdatedByType::class,
            'created_by_id' => 'integer',
        ];
    }

    protected static function newFactory(): TenantConfigVersionFactory
    {
        return TenantConfigVersionFactory::new();
    }
}
