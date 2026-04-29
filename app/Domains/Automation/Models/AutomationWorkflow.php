<?php

namespace App\Domains\Automation\Models;

use App\Domains\Automation\Enums\WorkflowStatus;
use App\Domains\Automation\Enums\WorkflowTriggerType;
use App\Models\Team;
use Database\Factories\Domains\Automation\AutomationWorkflowFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationWorkflow extends Model
{
    /** @use HasFactory<AutomationWorkflowFactory> */
    use HasFactory;

    protected $table = 'automation_workflows';

    protected $fillable = [
        'team_id',
        'code',
        'name',
        'description',
        'trigger_type',
        'trigger_conditions_json',
        'status',
        'version',
        'steps_json',
        'is_active',
    ];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    /**
     * @return HasMany<EscalationStep, $this>
     */
    public function escalationSteps(): HasMany
    {
        return $this->hasMany(EscalationStep::class)->orderBy('step_order');
    }

    /**
     * @return HasMany<WorkflowExecution, $this>
     */
    public function executions(): HasMany
    {
        return $this->hasMany(WorkflowExecution::class);
    }

    /**
     * @param  Builder<AutomationWorkflow>  $query
     */
    public function scopeAvailableToTeam(Builder $query, int $teamId): Builder
    {
        return $query->where(function (Builder $inner) use ($teamId) {
            $inner->where('team_id', $teamId)->orWhereNull('team_id');
        });
    }

    /**
     * @param  Builder<AutomationWorkflow>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('status', WorkflowStatus::Active);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger_type' => WorkflowTriggerType::class,
            'status' => WorkflowStatus::class,
            'trigger_conditions_json' => 'array',
            'steps_json' => 'array',
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }

    protected static function newFactory(): AutomationWorkflowFactory
    {
        return AutomationWorkflowFactory::new();
    }
}
