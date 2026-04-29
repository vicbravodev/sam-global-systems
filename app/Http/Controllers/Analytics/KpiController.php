<?php

namespace App\Http\Controllers\Analytics;

use App\Domains\Analytics\Enums\DimensionType;
use App\Domains\Analytics\Enums\PeriodType;
use App\Domains\Analytics\Models\KpiRecord;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KpiController extends Controller
{
    public function index(Request $request, Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', KpiRecord::class);

        $query = KpiRecord::query();

        if ($request->filled('kpi_code')) {
            $query->where('kpi_code', $request->string('kpi_code'));
        }

        if ($request->filled('period_type')) {
            $period = PeriodType::tryFrom($request->string('period_type'));
            if ($period) {
                $query->where('period_type', $period);
            }
        }

        if ($request->filled('dimension_type')) {
            $dimension = DimensionType::tryFrom($request->string('dimension_type'));
            if ($dimension) {
                $query->where('dimension_type', $dimension);
            }
        }

        if ($request->filled('dimension_reference')) {
            $query->where('dimension_reference', $request->string('dimension_reference'));
        }

        if ($request->filled('from')) {
            $query->where('period_start', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->where('period_end', '<=', $request->date('to'));
        }

        $records = $query->orderByDesc('period_start')
            ->paginate($request->integer('per_page', 25));

        return response()->json($records);
    }
}
