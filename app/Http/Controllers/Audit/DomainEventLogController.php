<?php

namespace App\Http\Controllers\Audit;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Audit\Models\DomainEventLog;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DomainEventLogController extends Controller
{
    public function index(Request $request, Team $current_team): JsonResponse
    {
        // Re-use the audit-log policy: anyone with `audit.view` on the
        // current team can read the underlying domain event log.
        $this->authorize('viewAny', AuditLog::class);

        $query = DomainEventLog::where('team_id', $current_team->id);

        if ($request->filled('event_name')) {
            $query->where('event_name', $request->input('event_name'));
        }

        if ($request->filled('correlation_id')) {
            $query->where('correlation_id', $request->input('correlation_id'));
        }

        if ($request->filled('aggregate_type')) {
            $query->where('aggregate_type', $request->input('aggregate_type'));
        }

        if ($request->filled('aggregate_id')) {
            $query->where('aggregate_id', $request->integer('aggregate_id'));
        }

        $events = $query->orderByDesc('occurred_at')
            ->paginate($request->integer('per_page', 25));

        return response()->json($events);
    }
}
