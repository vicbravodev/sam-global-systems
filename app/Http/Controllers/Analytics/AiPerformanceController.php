<?php

namespace App\Http\Controllers\Analytics;

use App\Domains\AI\Queries\OperatorVerdictMetricsQuery;
use App\Domains\Analytics\Enums\SnapshotType;
use App\Domains\Analytics\Models\AnalyticsSnapshot;
use App\Domains\Analytics\Models\KpiRecord;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiPerformanceController extends Controller
{
    public function index(Request $request, Team $current_team, OperatorVerdictMetricsQuery $operatorVerdicts): JsonResponse
    {
        $this->authorize('viewAiPerformance', KpiRecord::class);

        $days = max(1, min(365, $request->integer('days', 30)));

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
            // Bucle de feedback humano: etiquetas del operador y concordancia
            // IA-vs-operador en la ventana pedida (por defecto 30 días).
            'operator_verdicts' => [
                'window_days' => $days,
                ...$operatorVerdicts->execute($current_team->id, now()->subDays($days)),
            ],
        ]);
    }
}
