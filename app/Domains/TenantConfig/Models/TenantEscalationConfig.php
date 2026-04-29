<?php

namespace App\Domains\TenantConfig\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\Domains\TenantConfig\TenantEscalationConfigFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantEscalationConfig extends Model
{
    /** @use HasFactory<TenantEscalationConfigFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'tenant_escalation_configs';

    protected $fillable = [
        'team_id',
        'escalation_type',
        'trigger_conditions_json',
        'steps_json',
        'time_constraints_json',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger_conditions_json' => 'array',
            'steps_json' => 'array',
            'time_constraints_json' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): TenantEscalationConfigFactory
    {
        return TenantEscalationConfigFactory::new();
    }
}
