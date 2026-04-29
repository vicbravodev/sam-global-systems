<?php

namespace App\Domains\Decisions\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\Domains\Decisions\EscalationPolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EscalationPolicy extends Model
{
    /** @use HasFactory<EscalationPolicyFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'escalation_policies';

    protected $fillable = [
        'team_id',
        'code',
        'name',
        'description',
        'trigger_conditions_json',
        'escalation_steps_json',
        'max_wait_seconds',
        'requires_acknowledgement',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger_conditions_json' => 'array',
            'escalation_steps_json' => 'array',
            'max_wait_seconds' => 'integer',
            'requires_acknowledgement' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): EscalationPolicyFactory
    {
        return EscalationPolicyFactory::new();
    }
}
