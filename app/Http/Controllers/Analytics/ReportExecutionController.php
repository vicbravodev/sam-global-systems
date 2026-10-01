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
            if ($status !== null) {
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

        $path = $execution->file_path;

        // file_path lo escribe GenerateReport (ruta generada, nunca '0') y
        // ExpireOldReports lo pone a null al expirar.
        if ($path === null || $path === '') {
            throw new NotFoundHttpException('Report has no stored file');
        }

        $disk = Storage::disk('rustfs');
        $execution->loadMissing('outputFileObject');
        $fileObject = $execution->outputFileObject;

        try {
            if (! $disk->exists($path)) {
                throw new NotFoundHttpException('Report file is no longer available');
            }

            $contents = $disk->get($path);
            $mime = $fileObject?->content_type;

            if ($mime === null || $mime === '') {
                $detected = $disk->mimeType($path);
                $mime = is_string($detected) && $detected !== '' ? $detected : 'application/octet-stream';
            }
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

        // original_filename lo fija GenerateReport con basename() de la ruta generada.
        $originalFilename = $fileObject?->original_filename;
        $filename = $originalFilename !== null && $originalFilename !== '' ? $originalFilename : basename($path);

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
