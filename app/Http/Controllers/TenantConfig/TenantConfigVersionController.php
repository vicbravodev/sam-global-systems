<?php

namespace App\Http\Controllers\TenantConfig;

use App\Domains\TenantConfig\Models\TenantConfigVersion;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantConfigVersionController extends Controller
{
    public function index(Request $request, Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', TenantConfigVersion::class);

        $versions = TenantConfigVersion::withoutGlobalScopes()
            ->where('team_id', $current_team->id)
            ->orderByDesc('version')
            ->paginate($request->integer('per_page', 15));

        return response()->json($versions);
    }

    public function show(Team $current_team, TenantConfigVersion $configVersion): JsonResponse
    {
        $this->authorize('view', $configVersion);

        return response()->json(['data' => $configVersion]);
    }
}
