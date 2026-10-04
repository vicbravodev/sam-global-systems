<?php

namespace App\Http\Controllers\Drivers;

use App\Domains\Drivers\Actions\BuildHosDriverPanel;
use App\Domains\Drivers\Actions\ListHosFleet;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\HosDriverState;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Panel HOS (EE. UU.): la vista de flota (Inertia) y su espejo API, más el
 * panel de un chofer por API. HosMonitoringPolicy exige la feature
 * `hos_monitoring` y `drivers.view`.
 */
class HosPanelController extends Controller
{
    public function index(Team $current_team, ListHosFleet $listFleet): Response
    {
        $this->authorize('viewAny', HosDriverState::class);

        return Inertia::render('drivers/hos', [
            'fleet' => fn (): array => $listFleet->execute($current_team->id, now()),
        ]);
    }

    public function fleet(Team $current_team, ListHosFleet $listFleet): JsonResponse
    {
        $this->authorize('viewAny', HosDriverState::class);

        return response()->json(['data' => $listFleet->execute($current_team->id, now())]);
    }

    public function driver(Team $current_team, Driver $driver, BuildHosDriverPanel $panel): JsonResponse
    {
        abort_if($driver->team_id !== $current_team->id, 404);

        $this->authorize('view', $driver);
        $this->authorize('viewAny', HosDriverState::class);

        return response()->json(['data' => $panel->execute($driver, now())]);
    }
}
