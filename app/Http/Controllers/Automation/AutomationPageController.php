<?php

namespace App\Http\Controllers\Automation;

use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Enums\WorkflowStatus;
use App\Domains\Automation\Enums\WorkflowTriggerType;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Automation\Models\AutomationWorkflow;
use App\Domains\Automation\Support\TriggerConditionCatalog;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Automation page (Roadmap F12): workflow list with a simple builder
 * (trigger + steps) and the executions feed with retry / confirm / cancel.
 * Mutations reuse the Automation API controllers as web routes.
 */
class AutomationPageController extends Controller
{
    public function show(Team $current_team): Response
    {
        $this->authorize('viewAny', AutomationWorkflow::class);

        return Inertia::render('automation/index', [
            'workflows' => fn () => AutomationWorkflow::withoutGlobalScopes()
                ->where('team_id', $current_team->id)
                ->orderBy('name')
                ->get()
                ->map(fn (AutomationWorkflow $workflow): array => [
                    'id' => (int) $workflow->id,
                    'code' => $workflow->code,
                    'name' => $workflow->name,
                    'description' => $workflow->description,
                    'triggerType' => $workflow->trigger_type?->value,
                    'triggerConditions' => $workflow->trigger_conditions_json,
                    'status' => $workflow->status?->value,
                    'steps' => (array) ($workflow->steps_json ?? []),
                    'isActive' => (bool) $workflow->is_active,
                ])
                ->all(),
            'executions' => fn () => ActionExecution::withoutGlobalScopes()
                ->where('team_id', $current_team->id)
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (ActionExecution $execution): array => [
                    'id' => (int) $execution->id,
                    'actionType' => $execution->action_type?->value,
                    'status' => $execution->status?->value,
                    'executionMode' => $execution->execution_mode?->value,
                    'targetType' => $execution->target_type,
                    'targetReference' => $execution->target_reference,
                    'incidentId' => $execution->incident_id !== null ? (int) $execution->incident_id : null,
                    'attempts' => (int) $execution->attempts,
                    'errorMessage' => $execution->error_message,
                    'isStub' => (bool) (($execution->response_json ?? [])['stub'] ?? false),
                    'executedAt' => $execution->executed_at?->toIso8601String(),
                    'createdAt' => $execution->created_at?->toIso8601String(),
                ])
                ->all(),
            'options' => fn (): array => [
                'actionTypes' => array_map(fn (ActionType $type) => $type->value, ActionType::cases()),
                'triggerTypes' => array_map(fn (WorkflowTriggerType $type) => $type->value, WorkflowTriggerType::cases()),
                'statuses' => array_map(fn (WorkflowStatus $status) => $status->value, WorkflowStatus::cases()),
            ],
            'triggerConditionFields' => fn () => TriggerConditionCatalog::all(),
            'teamTargets' => fn (): array => [
                'users' => $current_team->members()
                    ->orderBy('name')
                    ->get(['users.id', 'users.name', 'users.email'])
                    ->map(fn ($user): array => [
                        'value' => (string) $user->id,
                        'label' => (string) $user->name,
                        'description' => (string) $user->email,
                    ])
                    ->all(),
                'roles' => array_map(fn (TeamRole $role): array => [
                    'value' => $role->value,
                    'label' => $role->label(),
                ], TeamRole::cases()),
            ],
            'canManage' => fn () => (bool) request()->user()?->can('create', AutomationWorkflow::class),
        ]);
    }
}
