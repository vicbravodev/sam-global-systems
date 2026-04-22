<?php

namespace App\Http\Controllers\Assets;

use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Enums\TelemetryType;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use App\Domains\Assets\Models\AssetTelemetrySnapshot;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssetController extends Controller
{
    public function index(Request $request, Team $current_team): JsonResponse
    {
        $query = Asset::with('assetType')
            ->where('team_id', $current_team->id);

        if ($request->filled('status')) {
            $status = AssetStatus::tryFrom($request->input('status'));
            if ($status) {
                $query->where('status', $status);
            }
        }

        if ($request->filled('type')) {
            $query->whereHas('assetType', fn ($q) => $q->where('code', $request->input('type')));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $assets = $query->orderByDesc('id')->paginate($request->integer('per_page', 15));

        return response()->json($assets);
    }

    public function show(Team $current_team, Asset $asset): JsonResponse
    {
        $asset->load(['assetType', 'devices', 'latestLocation']);

        return response()->json(['data' => $asset]);
    }

    public function locationHistory(Request $request, Team $current_team, Asset $asset): JsonResponse
    {
        $snapshots = AssetLocationSnapshot::where('asset_id', $asset->id)
            ->orderByDesc('recorded_at')
            ->cursorPaginate($request->integer('per_page', 50));

        return response()->json($snapshots);
    }

    public function telemetry(Request $request, Team $current_team, Asset $asset): JsonResponse
    {
        $query = AssetTelemetrySnapshot::where('asset_id', $asset->id);

        if ($request->filled('type')) {
            $telemetryType = TelemetryType::tryFrom($request->input('type'));
            if ($telemetryType) {
                $query->where('telemetry_type', $telemetryType);
            }
        }

        $snapshots = $query->orderByDesc('recorded_at')
            ->cursorPaginate($request->integer('per_page', 50));

        return response()->json($snapshots);
    }
}
