<?php

namespace App\Http\Controllers\Audit;

use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Audit\Models\AuditLog;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request, Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', AuditLog::class);

        $query = AuditLog::where('team_id', $current_team->id);

        if ($request->filled('actor_type')) {
            $actorType = AuditActorType::tryFrom($request->input('actor_type'));
            if ($actorType) {
                $query->where('actor_type', $actorType);
            }
        }

        if ($request->filled('category')) {
            $category = AuditCategory::tryFrom($request->input('category'));
            if ($category) {
                $query->where('category', $category);
            }
        }

        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->input('entity_type'));
        }

        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->integer('entity_id'));
        }

        if ($request->filled('from')) {
            $query->where('occurred_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->where('occurred_at', '<=', $request->input('to'));
        }

        $logs = $query->orderByDesc('occurred_at')
            ->paginate($request->integer('per_page', 25));

        return response()->json($logs);
    }

    public function show(Team $current_team, AuditLog $auditLog): JsonResponse
    {
        $this->authorize('view', $auditLog);

        return response()->json(['data' => $auditLog]);
    }
}
