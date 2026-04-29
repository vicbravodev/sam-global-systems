<?php

namespace App\Http\Controllers\Analytics;

use App\Domains\Analytics\Enums\SnapshotType;
use App\Domains\Analytics\Models\AnalyticsSnapshot;
use App\Domains\Analytics\Models\KpiRecord;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsDashboardController extends Controller
{
    public function index(Request $request, Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', KpiRecord::class);

        $overview = AnalyticsSnapshot::query()
            ->where('snapshot_type', SnapshotType::TenantOverview->value)
            ->orderByDesc('period_start')
            ->first();

        $latestKpis = KpiRecord::query()
            ->orderByDesc('calculated_at')
            ->limit(50)
            ->get();

        return response()->json([
            'overview' => $overview,
            'kpis' => $latestKpis,
        ]);
    }
}
