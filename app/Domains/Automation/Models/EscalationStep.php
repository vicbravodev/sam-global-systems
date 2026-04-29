<?php

namespace App\Domains\Automation\Models;

use App\Domains\Automation\Enums\EscalationStepType;
use Database\Factories\Domains\Automation\EscalationStepFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EscalationStep extends Model
{
    /** @use HasFactory<EscalationStepFactory> */
    use HasFactory;

    protected $table = 'escalation_steps';

    protected $fillable = [
        'automation_workflow_id',
        'step_order',
        'step_type',
        'target_type',
        'target_reference',
        'delay_seconds',
        'conditions_json',
        'fallback_action',
    ];

    /**
     * @return BelongsTo<AutomationWorkflow, $this>
     */
    public function automationWorkflow(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflow::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'step_type' => EscalationStepType::class,
            'step_order' => 'integer',
            'delay_seconds' => 'integer',
            'conditions_json' => 'array',
        ];
    }

    protected static function newFactory(): EscalationStepFactory
    {
        return EscalationStepFactory::new();
    }
}
