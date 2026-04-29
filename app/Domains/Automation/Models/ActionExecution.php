<?php

namespace App\Domains\Automation\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Automation\Enums\ActionExecutionSourceType;
use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Enums\ExecutionMode;
use Database\Factories\Domains\Automation\ActionExecutionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActionExecution extends Model
{
    /** @use HasFactory<ActionExecutionFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'action_executions';

    protected $fillable = [
        'team_id',
        'action_type',
        'source_type',
        'source_reference_id',
        'incident_id',
        'decision_id',
        'automation_workflow_id',
        'action_template_id',
        'status',
        'execution_mode',
        'target_type',
        'target_reference',
        'payload_json',
        'response_json',
        'error_message',
        'attempts',
        'executed_at',
    ];

    /**
     * @return BelongsTo<AutomationWorkflow, $this>
     */
    public function automationWorkflow(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflow::class);
    }

    /**
     * @return BelongsTo<ActionTemplate, $this>
     */
    public function actionTemplate(): BelongsTo
    {
        return $this->belongsTo(ActionTemplate::class);
    }

    /**
     * @return HasMany<ActionExecutionLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(ActionExecutionLog::class)->orderBy('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action_type' => ActionType::class,
            'source_type' => ActionExecutionSourceType::class,
            'status' => ActionExecutionStatus::class,
            'execution_mode' => ExecutionMode::class,
            'payload_json' => 'array',
            'response_json' => 'array',
            'attempts' => 'integer',
            'executed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): ActionExecutionFactory
    {
        return ActionExecutionFactory::new();
    }
}
