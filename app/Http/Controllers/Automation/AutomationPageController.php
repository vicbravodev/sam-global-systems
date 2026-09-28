<?php

namespace App\Http\Controllers\Automation;

use App\Domains\Automation\Enums\ActionExecutionSourceType;
use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Enums\WorkflowStatus;
use App\Domains\Automation\Enums\WorkflowTriggerType;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Automation\Models\AutomationWorkflow;
use App\Domains\Automation\Models\WorkflowExecution;
use App\Domains\Automation\Queries\WorkflowRunStats;
use App\Domains\Automation\Support\AutomationTargetLabels;
use App\Domains\Automation\Support\TriggerConditionCatalog;
use App\Domains\Incidents\Models\Incident;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Automation page (Roadmap F12): workflow list with a guided builder
 * (trigger + steps), a tenant-wide summary and the executions feed with
 * retry / confirm / cancel. Mutations reuse the Automation API controllers
 * as web routes.
 *
 * The executions feed covers the last {@see self::WINDOW_DAYS} days plus
 * every execution still waiting for confirmation (those need a human no
 * matter how old they are); the summary counts use the same definition so
 * a number in the pulse strip always matches the list it filters.
 */
class AutomationPageController extends Controller
{
    public const WINDOW_DAYS = 30;

    public const EXECUTIONS_LIMIT = 100;

    /** @var array<string, array<int, ActionExecutionStatus>> */
    private const STATUS_FILTERS = [
        'failed' => [ActionExecutionStatus::Failed],
        'pending' => [ActionExecutionStatus::Pending],
        'in_progress' => [ActionExecutionStatus::Queued, ActionExecutionStatus::Running, ActionExecutionStatus::Retrying],
        'completed' => [ActionExecutionStatus::Completed],
        'cancelled' => [ActionExecutionStatus::Cancelled],
    ];

    public function show(Request $request, Team $current_team): Response
    {
        $this->authorize('viewAny', AutomationWorkflow::class);

        $statusFilter = $request->string('execution_status')->toString();
        $statusFilter = array_key_exists($statusFilter, self::STATUS_FILTERS) ? $statusFilter : null;

        $workflowFilter = $request->integer('execution_workflow') ?: null;

        if ($workflowFilter !== null && ! AutomationWorkflow::query()
            ->where('team_id', $current_team->id)
            ->whereKey($workflowFilter)
            ->exists()) {
            $workflowFilter = null;
        }

        $targets = null;
        $targetLabels = function () use (&$targets, $current_team): AutomationTargetLabels {
            return $targets ??= new AutomationTargetLabels($current_team);
        };

        return Inertia::render('automation/index', [
            'workflows' => fn () => AutomationWorkflow::query()
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
                    // Etiqueta humana del destino de cada paso, en el mismo orden.
                    'stepTargets' => array_map(
                        fn ($step): string => is_array($step)
                            ? $targetLabels()->label(
                                isset($step['target_type']) ? (string) $step['target_type'] : null,
                                isset($step['target_reference']) ? (string) $step['target_reference'] : null,
                            )
                            : '—',
                        array_values((array) ($workflow->steps_json ?? [])),
                    ),
                    // Sólo el nombre del destino, para frases "WhatsApp a Monitorista".
                    'stepRecipients' => array_map(
                        fn ($step): ?string => is_array($step)
                            ? $targetLabels()->name(
                                isset($step['target_type']) ? (string) $step['target_type'] : null,
                                isset($step['target_reference']) ? (string) $step['target_reference'] : null,
                            )
                            : null,
                        array_values((array) ($workflow->steps_json ?? [])),
                    ),
                    'isActive' => (bool) $workflow->is_active,
                ])
                ->all(),
            'runStats' => fn () => app(WorkflowRunStats::class)->forTeam($current_team->id),
            'executions' => fn (): array => $this->executions($current_team, $targetLabels(), $statusFilter, $workflowFilter),
            'executionFilters' => [
                'status' => $statusFilter,
                'workflow' => $workflowFilter,
            ],
            'summary' => fn (): array => $this->summary($current_team),
            'options' => fn (): array => [
                'actionTypes' => array_map(
                    fn (ActionType $type) => ['value' => $type->value, 'label' => $type->label()],
                    ActionType::cases(),
                ),
                'triggerTypes' => array_map(
                    fn (WorkflowTriggerType $type) => ['value' => $type->value, 'label' => $type->label()],
                    WorkflowTriggerType::cases(),
                ),
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

    /**
     * Executions of the window (plus pending ones), newest first, with the
     * automation and the incident each one acted on resolved for the UI.
     *
     * @return array<int, array<string, mixed>>
     */
    private function executions(Team $team, AutomationTargetLabels $targets, ?string $status, ?int $workflowId): array
    {
        $executions = $this->windowQuery($team)
            ->when($status !== null, fn (Builder $query) => $query->whereIn(
                'status',
                array_map(fn (ActionExecutionStatus $case) => $case->value, self::STATUS_FILTERS[$status]),
            ))
            ->when($workflowId !== null, fn (Builder $query) => $query->where('automation_workflow_id', $workflowId))
            ->orderByDesc('id')
            ->limit(self::EXECUTIONS_LIMIT)
            ->get();

        $workflowNames = AutomationWorkflow::query()
            ->where('team_id', $team->id)
            ->whereIn('id', $executions->pluck('automation_workflow_id')->filter()->unique()->values())
            ->pluck('name', 'id');

        $incidentIds = $this->incidentIds($team, $executions);

        $incidents = Incident::query()
            ->where('team_id', $team->id)
            ->whereIn('id', array_values(array_unique(array_filter($incidentIds))))
            ->get(['id', 'number', 'title'])
            ->keyBy('id');

        return $executions
            ->map(function (ActionExecution $execution) use ($targets, $workflowNames, $incidentIds, $incidents): array {
                $incident = $incidents->get($incidentIds[$execution->id] ?? 0);
                $workflowId = $execution->automation_workflow_id !== null ? (int) $execution->automation_workflow_id : null;

                return [
                    'id' => (int) $execution->id,
                    'actionType' => $execution->action_type?->value,
                    'status' => $execution->status?->value,
                    'executionMode' => $execution->execution_mode?->value,
                    'targetType' => $execution->target_type,
                    'targetReference' => $execution->target_reference,
                    'targetLabel' => $targets->label($execution->target_type, $execution->target_reference),
                    'targetName' => $targets->name($execution->target_type, $execution->target_reference),
                    'statusLabel' => $execution->status?->label(),
                    'sourceType' => $execution->source_type?->value,
                    'workflowId' => $workflowId,
                    'workflowName' => $workflowId !== null ? ($workflowNames[$workflowId] ?? null) : null,
                    'incidentId' => $incident !== null ? (int) $incident->id : null,
                    'incidentReference' => $incident?->reference(),
                    'incidentTitle' => $incident?->title,
                    'attempts' => (int) $execution->attempts,
                    'errorMessage' => $execution->error_message,
                    'isStub' => (bool) (($execution->response_json ?? [])['stub'] ?? false),
                    'executedAt' => $execution->executed_at?->toIso8601String(),
                    'createdAt' => $execution->created_at?->toIso8601String(),
                ];
            })
            ->all();
    }

    /**
     * Incident each execution acted on: its own `incident_id`, the incident
     * that sourced it directly, or — for workflow steps — the incident that
     * triggered the workflow run. Ids are only candidates: the caller loads
     * them constrained to the team, so a foreign id never resolves.
     *
     * @param  Collection<int, ActionExecution>  $executions
     * @return array<int, int|null> execution id => incident id
     */
    private function incidentIds(Team $team, Collection $executions): array
    {
        $incidentSources = [ActionExecutionSourceType::Incident, ActionExecutionSourceType::Escalation];

        $workflowRunIds = $executions
            ->filter(fn (ActionExecution $execution) => $execution->incident_id === null
                && $execution->source_type === ActionExecutionSourceType::Workflow
                && ctype_digit((string) $execution->source_reference_id))
            ->map(fn (ActionExecution $execution) => (int) $execution->source_reference_id)
            ->unique()
            ->values();

        $runIncidents = WorkflowExecution::query()
            ->where('team_id', $team->id)
            ->whereIn('id', $workflowRunIds)
            ->whereIn('source_type', array_map(fn (ActionExecutionSourceType $case) => $case->value, $incidentSources))
            ->get(['id', 'source_reference_id'])
            ->mapWithKeys(fn (WorkflowExecution $run): array => [
                (int) $run->id => ctype_digit((string) $run->source_reference_id) ? (int) $run->source_reference_id : null,
            ]);

        $ids = [];

        foreach ($executions as $execution) {
            $reference = (string) $execution->source_reference_id;

            $ids[(int) $execution->id] = match (true) {
                $execution->incident_id !== null => (int) $execution->incident_id,
                in_array($execution->source_type, $incidentSources, true) && ctype_digit($reference) => (int) $reference,
                $execution->source_type === ActionExecutionSourceType::Workflow && ctype_digit($reference) => $runIncidents[(int) $reference] ?? null,
                default => null,
            };
        }

        return $ids;
    }

    /**
     * Tenant-wide counters for the pulse strip; never narrowed by the
     * active filters.
     *
     * @return array{workflows: array{total: int, active: int, inactive: int}, executions: array<string, int>}
     */
    private function summary(Team $team): array
    {
        $workflows = AutomationWorkflow::query()
            ->where('team_id', $team->id)
            ->get(['id', 'is_active', 'status']);

        $total = $workflows->count();
        $active = $workflows
            ->filter(fn (AutomationWorkflow $workflow) => $workflow->is_active && $workflow->status === WorkflowStatus::Active)
            ->count();

        $byStatus = $this->windowQuery($team)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $executions = ['total' => (int) $byStatus->sum()];

        foreach (self::STATUS_FILTERS as $key => $statuses) {
            $executions[$key] = (int) collect($statuses)->sum(fn (ActionExecutionStatus $case) => $byStatus[$case->value] ?? 0);
        }

        return [
            'workflows' => [
                'total' => $total,
                'active' => $active,
                'inactive' => $total - $active,
            ],
            'executions' => $executions,
        ];
    }

    /**
     * @return Builder<ActionExecution>
     */
    private function windowQuery(Team $team): Builder
    {
        $since = Carbon::now()->subDays(self::WINDOW_DAYS);

        return ActionExecution::query()
            ->where('team_id', $team->id)
            ->where(fn (Builder $query) => $query
                ->where('created_at', '>=', $since)
                ->orWhere('status', ActionExecutionStatus::Pending->value));
    }
}
