<?php

namespace App\Http\Controllers\Audit;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Audit\Models\ChangeHistory;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChangeHistoryController extends Controller
{
    public function index(Request $request, Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', AuditLog::class);

        $query = ChangeHistory::where('team_id', $current_team->id);

        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->input('entity_type'));
        }

        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->integer('entity_id'));
        }

        if ($request->filled('change_type')) {
            $query->where('change_type', $request->input('change_type'));
        }

        $changes = $query->orderByDesc('occurred_at')
            ->paginate($request->integer('per_page', 25));

        return response()->json($changes);
    }
}
