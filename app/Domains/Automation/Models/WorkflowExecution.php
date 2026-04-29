<?php

namespace App\Domains\Automation\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Automation\Enums\WorkflowExecutionStatus;
use Database\Factories\Domains\Automation\WorkflowExecutionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowExecution extends Model
{
    /** @use HasFactory<WorkflowExecutionFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'workflow_executions';

    protected $fillable = [
        'team_id',
        'automation_workflow_id',
        'source_type',
        'source_reference_id',
        'status',
        'started_at',
        'completed_at',
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
            'status' => WorkflowExecutionStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): WorkflowExecutionFactory
    {
        return WorkflowExecutionFactory::new();
    }
}
