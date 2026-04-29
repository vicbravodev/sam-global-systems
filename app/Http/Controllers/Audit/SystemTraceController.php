<?php

namespace App\Http\Controllers\Audit;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Audit\Models\SystemTrace;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;

class SystemTraceController extends Controller
{
    public function show(Team $current_team, string $traceId): JsonResponse
    {
        $this->authorize('viewAny', AuditLog::class);

        $spans = SystemTrace::where('team_id', $current_team->id)
            ->where('trace_id', $traceId)
            ->orderBy('started_at')
            ->get();

        return response()->json([
            'data' => [
                'trace_id' => $traceId,
                'spans' => $spans,
            ],
        ]);
    }
}
