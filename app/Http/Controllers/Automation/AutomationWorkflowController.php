<?php

namespace App\Http\Controllers\Automation;

use App\Domains\Automation\Enums\ActionExecutionSourceType;
use App\Domains\Automation\Models\AutomationWorkflow;
use App\Domains\Automation\Services\RunAutomationWorkflow;
use App\Http\Controllers\Controller;
use App\Http\Requests\Automation\StoreAutomationWorkflowRequest;
use App\Http\Requests\Automation\UpdateAutomationWorkflowRequest;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AutomationWorkflowController extends Controller
{
    public function index(Request $request, Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', AutomationWorkflow::class);

        $query = AutomationWorkflow::query()->availableToTeam($current_team->id);

        if ($request->filled('trigger_type')) {
            $query->where('trigger_type', (string) $request->input('trigger_type'));
        }

        if ($request->boolean('only_active')) {
            $query->active();
        }

        $workflows = $query->orderBy('id')->paginate($request->integer('per_page', 25));

        return response()->json($workflows);
    }

    public function show(Team $current_team, AutomationWorkflow $workflow): JsonResponse
    {
        $this->authorize('view', $workflow);

        return response()->json(['data' => $workflow]);
    }

    public function store(StoreAutomationWorkflowRequest $request, Team $current_team): JsonResponse
    {
        $this->authorize('create', AutomationWorkflow::class);

        $payload = $request->validated();
        $payload['team_id'] = $current_team->id;
        $payload['version'] = $payload['version'] ?? 1;
        $payload['is_active'] = $payload['is_active'] ?? true;

        $workflow = AutomationWorkflow::create($payload);

        return response()->json(['data' => $workflow], 201);
    }

    public function update(UpdateAutomationWorkflowRequest $request, Team $current_team, AutomationWorkflow $workflow): JsonResponse
    {
        $this->authorize('update', $workflow);

        $workflow->update($request->validated());

        return response()->json(['data' => $workflow->fresh()]);
    }

    public function destroy(Team $current_team, AutomationWorkflow $workflow): JsonResponse
    {
        $this->authorize('delete', $workflow);

        $workflow->delete();

        return response()->json(null, 204);
    }

    public function trigger(
        Request $request,
        Team $current_team,
        AutomationWorkflow $workflow,
        RunAutomationWorkflow $runAutomationWorkflow,
    ): JsonResponse {
        $this->authorize('trigger', $workflow);

        $sourceReferenceId = $request->input('source_reference_id');

        $execution = $runAutomationWorkflow->execute(
            workflow: $workflow,
            teamId: $current_team->id,
            sourceType: ActionExecutionSourceType::Manual,
            sourceReferenceId: is_string($sourceReferenceId) || is_int($sourceReferenceId)
                ? (string) $sourceReferenceId
                : null,
        );

        if ($execution === null) {
            return response()->json([
                'message' => 'Ya existe una ejecución del workflow para este origen.',
            ], 409);
        }

        return response()->json(['data' => $execution], 202);
    }
}
