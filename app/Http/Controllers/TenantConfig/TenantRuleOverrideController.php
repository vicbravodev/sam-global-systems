<?php

namespace App\Http\Controllers\TenantConfig;

use App\Domains\TenantConfig\Enums\RuleOverrideType;
use App\Domains\TenantConfig\Models\TenantRuleOverride;
use App\Domains\TenantConfig\Support\CacheKeys;
use App\Http\Controllers\Controller;
use App\Http\Requests\TenantConfig\StoreTenantRuleOverrideRequest;
use App\Http\Requests\TenantConfig\UpdateTenantRuleOverrideRequest;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class TenantRuleOverrideController extends Controller
{
    public function index(Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', TenantRuleOverride::class);

        $overrides = TenantRuleOverride::withoutGlobalScopes()
            ->where('team_id', $current_team->id)
            ->orderBy('base_rule_code')
            ->get();

        return response()->json(['data' => $overrides]);
    }

    public function store(StoreTenantRuleOverrideRequest $request, Team $current_team): JsonResponse
    {
        $this->authorize('create', TenantRuleOverride::class);

        $override = TenantRuleOverride::withoutGlobalScopes()->create([
            'team_id' => $current_team->id,
            'base_rule_code' => $request->validated('base_rule_code'),
            'override_type' => RuleOverrideType::from($request->validated('override_type')),
            'override_config_json' => $request->validated('override_config'),
            'reason' => $request->validated('reason'),
            'is_active' => $request->boolean('is_active', true),
        ]);

        Cache::forget(CacheKeys::ruleOverride($current_team->id, $override->base_rule_code));

        return response()->json(['data' => $override], 201);
    }

    public function update(UpdateTenantRuleOverrideRequest $request, Team $current_team, TenantRuleOverride $override): JsonResponse
    {
        $this->authorize('update', $override);

        $payload = array_filter([
            'override_type' => $request->filled('override_type')
                ? RuleOverrideType::from($request->validated('override_type'))
                : null,
            'override_config_json' => $request->validated('override_config'),
            'reason' => $request->validated('reason'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
        ], fn ($value) => $value !== null);

        $override->fill($payload)->save();

        Cache::forget(CacheKeys::ruleOverride($current_team->id, $override->base_rule_code));

        return response()->json(['data' => $override->fresh()]);
    }

    public function destroy(Team $current_team, TenantRuleOverride $override): JsonResponse
    {
        $this->authorize('delete', $override);

        $ruleCode = $override->base_rule_code;
        $override->delete();

        Cache::forget(CacheKeys::ruleOverride($current_team->id, $ruleCode));

        return response()->json(null, 204);
    }
}
