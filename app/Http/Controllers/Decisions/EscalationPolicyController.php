<?php

namespace App\Http\Controllers\Decisions;

use App\Domains\Decisions\Models\EscalationPolicy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Decisions\StoreEscalationPolicyRequest;
use App\Http\Requests\Decisions\UpdateEscalationPolicyRequest;
use App\Models\Team;
use Illuminate\Http\JsonResponse;

class EscalationPolicyController extends Controller
{
    public function index(Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', EscalationPolicy::class);

        $policies = EscalationPolicy::query()
            ->where('team_id', $current_team->id)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $policies]);
    }

    public function store(StoreEscalationPolicyRequest $request, Team $current_team): JsonResponse
    {
        $this->authorize('create', EscalationPolicy::class);

        $policy = EscalationPolicy::create([
            'team_id' => $current_team->id,
            'code' => (string) $request->string('code'),
            'name' => (string) $request->string('name'),
            'description' => $request->input('description'),
            'trigger_conditions_json' => $request->input('trigger_conditions_json'),
            'escalation_steps_json' => (array) $request->input('escalation_steps_json'),
            'max_wait_seconds' => $request->input('max_wait_seconds'),
            'requires_acknowledgement' => (bool) $request->boolean('requires_acknowledgement'),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json(['data' => $policy], 201);
    }

    public function update(UpdateEscalationPolicyRequest $request, Team $current_team, EscalationPolicy $policy): JsonResponse
    {
        $this->authorize('update', $policy);

        $policy->fill($request->validated());
        $policy->save();

        return response()->json(['data' => $policy->fresh()]);
    }
}
