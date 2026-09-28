<?php

namespace App\Http\Controllers\Analytics;

use App\Domains\Analytics\Enums\ReportExecutionStatus;
use App\Domains\Analytics\Models\ReportExecution;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Support\Http\PerPage;
use App\Support\ObjectStorageFailure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

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
            ->paginate(PerPage::from($request, 25));

        return response()->json($executions);
    }

    public function show(Team $current_team, ReportExecution $execution): JsonResponse
    {
        $this->authorize('view', $execution);

        $execution->load('definition');

        return response()->json(['data' => $execution]);
    }

    public function download(Request $request, Team $current_team, ReportExecution $execution): Response|JsonResponse|RedirectResponse
    {
        $this->authorize('download', $execution);

        if (! $execution->file_path) {
            throw new NotFoundHttpException('Report has no stored file');
        }

        $disk = Storage::disk('rustfs');
        $execution->loadMissing('outputFileObject');
        $fileObject = $execution->outputFileObject;

        try {
            if (! $disk->exists($execution->file_path)) {
                throw new NotFoundHttpException('Report file is no longer available');
            }

            $contents = $disk->get($execution->file_path);
            $mime = $fileObject?->content_type
                ?: ($disk->mimeType($execution->file_path) ?: 'application/octet-stream');
        } catch (Throwable $e) {
            if (! ObjectStorageFailure::matches($e)) {
                throw $e;
            }

            ObjectStorageFailure::report('report_download', $e, [
                'team_id' => $current_team->id,
                'report_execution_id' => $execution->id,
            ]);

            return $this->storageUnavailable($request, $current_team);
        }

        $filename = $fileObject?->original_filename ?: basename($execution->file_path);

        return response((string) $contents, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * The web route is a plain link from the analytics page: send the user
     * back there with a toast. API clients get a 503 with the same message.
     */
    private function storageUnavailable(Request $request, Team $team): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => ObjectStorageFailure::USER_MESSAGE], 503);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => 'No se pudo descargar el reporte. '.ObjectStorageFailure::USER_MESSAGE]);

        return redirect()->route('analytics.show', ['current_team' => $team]);
    }
}
