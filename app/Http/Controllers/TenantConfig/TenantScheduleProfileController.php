<?php

namespace App\Http\Controllers\TenantConfig;

use App\Domains\TenantConfig\Models\TenantScheduleProfile;
use App\Domains\TenantConfig\Support\CacheKeys;
use App\Http\Controllers\Controller;
use App\Http\Requests\TenantConfig\UpdateTenantScheduleProfileRequest;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class TenantScheduleProfileController extends Controller
{
    public function index(Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', TenantScheduleProfile::class);

        $profiles = TenantScheduleProfile::withoutGlobalScopes()
            ->where('team_id', $current_team->id)
            ->orderBy('profile_code')
            ->get();

        return response()->json(['data' => $profiles]);
    }

    public function update(UpdateTenantScheduleProfileRequest $request, Team $current_team, TenantScheduleProfile $scheduleProfile): JsonResponse
    {
        $this->authorize('update', $scheduleProfile);

        $payload = array_filter([
            'profile_code' => $request->validated('profile_code'),
            'timezone' => $request->validated('timezone'),
            'operating_hours_json' => $request->validated('operating_hours'),
            'holidays_json' => $request->validated('holidays'),
            'shift_rules_json' => $request->validated('shift_rules'),
            'after_hours_behavior_json' => $request->validated('after_hours_behavior'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
        ], fn ($value) => $value !== null);

        $scheduleProfile->fill($payload)->save();

        Cache::forget(CacheKeys::schedule($current_team->id));

        return response()->json(['data' => $scheduleProfile->fresh()]);
    }
}
