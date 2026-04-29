<?php

namespace App\Domains\TenantConfig\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\TenantConfig\Enums\SettingGroup;
use App\Domains\TenantConfig\Enums\SettingUpdatedByType;
use App\Domains\TenantConfig\Enums\SettingValueType;
use Database\Factories\Domains\TenantConfig\TenantSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantSetting extends Model
{
    /** @use HasFactory<TenantSettingFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'tenant_settings';

    protected $fillable = [
        'team_id',
        'setting_key',
        'setting_group',
        'value_json',
        'value_type',
        'version',
        'is_active',
        'updated_by_type',
        'updated_by_id',
    ];

    /**
     * Coerce the stored JSON value back to its declared scalar/array type.
     */
    public function getTypedValueAttribute(): mixed
    {
        $raw = $this->value_json;

        if ($raw === null) {
            return null;
        }

        if (is_array($raw) && array_key_exists('value', $raw) && count($raw) === 1) {
            return $raw['value'];
        }

        return $raw;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'setting_group' => SettingGroup::class,
            'value_json' => 'array',
            'value_type' => SettingValueType::class,
            'version' => 'integer',
            'is_active' => 'boolean',
            'updated_by_type' => SettingUpdatedByType::class,
            'updated_by_id' => 'integer',
        ];
    }

    protected static function newFactory(): TenantSettingFactory
    {
        return TenantSettingFactory::new();
    }
}
