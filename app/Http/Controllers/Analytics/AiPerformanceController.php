<?php

namespace App\Http\Controllers\Analytics;

use App\Domains\Analytics\Enums\SnapshotType;
use App\Domains\Analytics\Models\AnalyticsSnapshot;
use App\Domains\Analytics\Models\KpiRecord;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;

class AiPerformanceController extends Controller
{
    public function index(Team $current_team): JsonResponse
    {
        $this->authorize('viewAiPerformance', KpiRecord::class);

        $snapshot = AnalyticsSnapshot::query()
            ->where('snapshot_type', SnapshotType::AiPerformance->value)
            ->orderByDesc('period_start')
            ->first();

        $aiKpis = KpiRecord::query()
            ->where('kpi_code', 'like', 'ai_%')
            ->orderByDesc('period_start')
            ->limit(50)
            ->get();

        return response()->json([
            'snapshot' => $snapshot,
            'kpis' => $aiKpis,
        ]);
    }
}
