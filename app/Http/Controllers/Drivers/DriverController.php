<?php

namespace App\Http\Controllers\Drivers;

use App\Domains\Drivers\Enums\DriverStatus;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverAssignment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Drivers\UpdateDriverContactsRequest;
use App\Http\Requests\Drivers\UpdateDriverDocumentsRequest;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverController extends Controller
{
    public function index(Request $request, Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', Driver::class);

        $query = Driver::where('team_id', $current_team->id);

        if ($request->filled('status')) {
            $status = DriverStatus::tryFrom($request->input('status'));
            if ($status) {
                $query->where('status', $status);
            }
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('employee_code', 'like', "%{$search}%");
            });
        }

        $drivers = $query->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

        return response()->json($drivers);
    }

    public function show(Team $current_team, Driver $driver): JsonResponse
    {
        $this->authorize('view', $driver);

        $driver->load(['contacts', 'documents', 'riskProfile', 'currentAssignment.asset']);

        return response()->json(['data' => $driver]);
    }

    public function assignments(Request $request, Team $current_team, Driver $driver): JsonResponse
    {
        $this->authorize('view', $driver);

        $assignments = DriverAssignment::with('asset')
            ->where('driver_id', $driver->id)
            ->orderByDesc('started_at')
            ->paginate($request->integer('per_page', 15));

        return response()->json($assignments);
    }

    public function riskProfile(Team $current_team, Driver $driver): JsonResponse
    {
        $this->authorize('view', $driver);

        $driver->load('riskProfile');

        return response()->json(['data' => $driver->riskProfile]);
    }

    public function updateContacts(UpdateDriverContactsRequest $request, Team $current_team, Driver $driver): JsonResponse
    {
        $this->authorize('updateContacts', $driver);

        $driver->contacts()->delete();

        foreach ($request->validated('contacts') as $contactData) {
            $driver->contacts()->create($contactData);
        }

        return response()->json([
            'data' => $driver->fresh()->load('contacts'),
        ]);
    }

    public function updateDocuments(UpdateDriverDocumentsRequest $request, Team $current_team, Driver $driver): JsonResponse
    {
        $this->authorize('updateDocuments', $driver);

        $driver->documents()->delete();

        foreach ($request->validated('documents') as $documentData) {
            $driver->documents()->create($documentData);
        }

        return response()->json([
            'data' => $driver->fresh()->load('documents'),
        ]);
    }
}
