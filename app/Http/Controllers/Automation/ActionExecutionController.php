<?php

namespace App\Http\Controllers\Automation;

use App\Domains\Automation\Actions\RetryFailedAction;
use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Jobs\ExecuteActionJob;
use App\Domains\Automation\Models\ActionExecution;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActionExecutionController extends Controller
{
    public function index(Request $request, Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', ActionExecution::class);

        $query = ActionExecution::query()->where('team_id', $current_team->id);

        if ($request->filled('status')) {
            $status = ActionExecutionStatus::tryFrom((string) $request->input('status'));
            if ($status) {
                $query->where('status', $status);
            }
        }

        if ($request->filled('incident_id')) {
            $query->where('incident_id', $request->integer('incident_id'));
        }

        $executions = $query->orderByDesc('id')->paginate($request->integer('per_page', 25));

        return response()->json($executions);
    }

    public function show(Team $current_team, ActionExecution $execution): JsonResponse
    {
        $this->authorize('view', $execution);

        $execution->load('logs');

        return response()->json(['data' => $execution]);
    }

    public function retry(
        Team $current_team,
        ActionExecution $execution,
        RetryFailedAction $retryFailedAction,
    ): JsonResponse {
        $this->authorize('manage', $execution);

        if ($execution->status !== ActionExecutionStatus::Failed) {
            return response()->json([
                'message' => 'Only failed executions can be retried.',
            ], 422);
        }

        $requeued = $retryFailedAction->execute($execution);

        if (! $requeued) {
            return response()->json([
                'message' => 'Retry budget exhausted; execution remains failed.',
            ], 422);
        }

        return response()->json(['data' => $execution->fresh()], 202);
    }

    public function confirm(Team $current_team, ActionExecution $execution): JsonResponse
    {
        $this->authorize('manage', $execution);

        if ($execution->status !== ActionExecutionStatus::Pending) {
            return response()->json([
                'message' => 'Only pending executions can be confirmed.',
            ], 422);
        }

        $execution->update(['status' => ActionExecutionStatus::Queued]);
        ExecuteActionJob::dispatch($execution->id);

        return response()->json(['data' => $execution->fresh()], 202);
    }

    public function cancel(Team $current_team, ActionExecution $execution): JsonResponse
    {
        $this->authorize('manage', $execution);

        if (in_array($execution->status, [
            ActionExecutionStatus::Completed,
            ActionExecutionStatus::Cancelled,
        ], true)) {
            return response()->json([
                'message' => 'Execution is already in a terminal state.',
            ], 422);
        }

        $execution->update(['status' => ActionExecutionStatus::Cancelled]);

        return response()->json(['data' => $execution->fresh()]);
    }
}
