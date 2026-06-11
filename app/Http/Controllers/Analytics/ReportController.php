<?php

namespace App\Http\Controllers\Analytics;

use App\Domains\Analytics\Enums\ReportOutputFormat;
use App\Domains\Analytics\Enums\ReportRequestedByType;
use App\Domains\Analytics\Jobs\GenerateReportJob;
use App\Domains\Analytics\Models\ReportDefinition;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function index(Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', ReportDefinition::class);

        $reports = ReportDefinition::query()
            ->where(function ($q) use ($current_team) {
                $q->whereNull('team_id')
                    ->orWhere('team_id', $current_team->id);
            })
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $reports]);
    }

    public function generate(Request $request, Team $current_team, ReportDefinition $report): JsonResponse
    {
        $this->authorize('generate', $report);

        $format = ReportOutputFormat::tryFrom((string) $request->input('format', 'json'))
            ?? ReportOutputFormat::Json;

        GenerateReportJob::dispatch(
            reportDefinitionId: $report->id,
            teamId: $current_team->id,
            outputFormat: $format->value,
            requestedByType: ReportRequestedByType::User->value,
            requestedById: $request->user()?->id,
            filters: $request->input('filters'),
        );

        return response()->json([
            'message' => 'Generación del reporte encolada',
            'report_definition_id' => $report->id,
            'format' => $format->value,
        ], 202);
    }
}
