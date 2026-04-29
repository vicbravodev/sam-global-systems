<?php

namespace App\Http\Controllers\TenantConfig;

use App\Domains\TenantConfig\Models\TenantEscalationConfig;
use App\Http\Controllers\Controller;
use App\Http\Requests\TenantConfig\StoreTenantEscalationConfigRequest;
use App\Http\Requests\TenantConfig\UpdateTenantEscalationConfigRequest;
use App\Models\Team;
use Illuminate\Http\JsonResponse;

class TenantEscalationConfigController extends Controller
{
    public function index(Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', TenantEscalationConfig::class);

        $configs = TenantEscalationConfig::withoutGlobalScopes()
            ->where('team_id', $current_team->id)
            ->orderBy('escalation_type')
            ->get();

        return response()->json(['data' => $configs]);
    }

    public function store(StoreTenantEscalationConfigRequest $request, Team $current_team): JsonResponse
    {
        $this->authorize('create', TenantEscalationConfig::class);

        $config = TenantEscalationConfig::withoutGlobalScopes()->create([
            'team_id' => $current_team->id,
            'escalation_type' => $request->validated('escalation_type'),
            'trigger_conditions_json' => $request->validated('trigger_conditions'),
            'steps_json' => $request->validated('steps'),
            'time_constraints_json' => $request->validated('time_constraints'),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json(['data' => $config], 201);
    }

    public function update(UpdateTenantEscalationConfigRequest $request, Team $current_team, TenantEscalationConfig $escalationConfig): JsonResponse
    {
        $this->authorize('update', $escalationConfig);

        $payload = array_filter([
            'escalation_type' => $request->validated('escalation_type'),
            'trigger_conditions_json' => $request->validated('trigger_conditions'),
            'steps_json' => $request->validated('steps'),
            'time_constraints_json' => $request->validated('time_constraints'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
        ], fn ($value) => $value !== null);

        $escalationConfig->fill($payload)->save();

        return response()->json(['data' => $escalationConfig->fresh()]);
    }
}
