<?php

namespace App\Http\Controllers\Context;

use App\Domains\Context\Actions\ResolveGeofenceContext;
use App\Domains\Context\Enums\GeofenceCategory;
use App\Domains\Context\Enums\GeofenceType;
use App\Domains\Context\Models\Geofence;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GeofenceController extends Controller
{
    public function index(Request $request, Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', Geofence::class);

        $query = Geofence::where('team_id', $current_team->id);

        if ($request->filled('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        if ($request->filled('category')) {
            $category = GeofenceCategory::tryFrom($request->input('category'));
            if ($category) {
                $query->where('category', $category);
            }
        }

        $geofences = $query->orderByDesc('id')->paginate($request->integer('per_page', 15));

        return response()->json($geofences);
    }

    public function store(Request $request, Team $current_team): JsonResponse
    {
        $this->authorize('create', Geofence::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:64'],
            'geofence_type' => ['required', 'string'],
            'category' => ['required', 'string'],
            'geometry_json' => ['required', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            'metadata_json' => ['sometimes', 'array', 'nullable'],
        ]);

        $data['geofence_type'] = GeofenceType::from($data['geofence_type']);
        $data['category'] = GeofenceCategory::from($data['category']);
        $data['team_id'] = $current_team->id;
        $data['is_active'] = $data['is_active'] ?? true;

        $geofence = Geofence::create($data);

        ResolveGeofenceContext::invalidateCacheForTeam($current_team->id);

        return response()->json(['data' => $geofence], 201);
    }

    public function update(Request $request, Team $current_team, Geofence $geofence): JsonResponse
    {
        $this->authorize('update', $geofence);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:64'],
            'geofence_type' => ['sometimes', 'string'],
            'category' => ['sometimes', 'string'],
            'geometry_json' => ['sometimes', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            'metadata_json' => ['sometimes', 'array', 'nullable'],
        ]);

        if (isset($data['geofence_type'])) {
            $data['geofence_type'] = GeofenceType::from($data['geofence_type']);
        }

        if (isset($data['category'])) {
            $data['category'] = GeofenceCategory::from($data['category']);
        }

        $geofence->update($data);

        ResolveGeofenceContext::invalidateCacheForTeam($current_team->id);

        return response()->json(['data' => $geofence->fresh()]);
    }

    public function destroy(Team $current_team, Geofence $geofence): JsonResponse
    {
        $this->authorize('delete', $geofence);

        $geofence->delete();

        ResolveGeofenceContext::invalidateCacheForTeam($current_team->id);

        return response()->json(['data' => ['deleted' => true]]);
    }
}
