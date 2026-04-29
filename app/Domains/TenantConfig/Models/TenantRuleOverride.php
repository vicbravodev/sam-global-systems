<?php

namespace App\Domains\TenantConfig\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\TenantConfig\Enums\RuleOverrideType;
use Database\Factories\Domains\TenantConfig\TenantRuleOverrideFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantRuleOverride extends Model
{
    /** @use HasFactory<TenantRuleOverrideFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'tenant_rule_overrides';

    protected $fillable = [
        'team_id',
        'base_rule_code',
        'override_type',
        'override_config_json',
        'reason',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'override_type' => RuleOverrideType::class,
            'override_config_json' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): TenantRuleOverrideFactory
    {
        return TenantRuleOverrideFactory::new();
    }
}
