<?php

namespace App\Http\Controllers\Analytics;

use App\Domains\Analytics\Enums\ReportExecutionStatus;
use App\Domains\Analytics\Models\ReportExecution;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ReportExecutionController extends Controller
{
    public function index(Request $request, Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', ReportExecution::class);

        $query = ReportExecution::query()->with('definition');

        if ($request->filled('status')) {
            $status = ReportExecutionStatus::tryFrom($request->string('status'));
            if ($status) {
                $query->where('status', $status);
            }
        }

        if ($request->filled('report_definition_id')) {
            $query->where('report_definition_id', $request->integer('report_definition_id'));
        }

        $executions = $query->orderByDesc('id')
            ->paginate($request->integer('per_page', 25));

        return response()->json($executions);
    }

    public function show(Team $current_team, ReportExecution $execution): JsonResponse
    {
        $this->authorize('view', $execution);

        $execution->load('definition');

        return response()->json(['data' => $execution]);
    }

    public function download(Team $current_team, ReportExecution $execution): Response
    {
        $this->authorize('download', $execution);

        if (! $execution->file_path) {
            throw new NotFoundHttpException('Report has no stored file');
        }

        $disk = Storage::disk('rustfs');

        if (! $disk->exists($execution->file_path)) {
            throw new NotFoundHttpException('Report file is no longer available');
        }

        $contents = $disk->get($execution->file_path);
        $filename = basename($execution->file_path);
        $mime = $disk->mimeType($execution->file_path) ?: 'application/octet-stream';

        return response((string) $contents, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
