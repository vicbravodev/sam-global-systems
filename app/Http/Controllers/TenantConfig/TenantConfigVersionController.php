<?php

namespace App\Http\Controllers\TenantConfig;

use App\Domains\TenantConfig\Models\TenantConfigVersion;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Support\Http\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantConfigVersionController extends Controller
{
    public function index(Request $request, Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', TenantConfigVersion::class);

        $versions = TenantConfigVersion::query()
            ->where('team_id', $current_team->id)
            ->orderByDesc('version')
            ->paginate(PerPage::from($request, 15));

        return response()->json($versions);
    }

    public function show(Team $current_team, TenantConfigVersion $configVersion): JsonResponse
    {
        $this->authorize('view', $configVersion);

        return response()->json(['data' => $configVersion]);
    }
}
