<?php

namespace App\Http\Controllers\Decisions;

use App\Domains\Decisions\Models\DecisionRule;
use App\Domains\Decisions\Models\RuleSet;
use App\Http\Controllers\Controller;
use App\Http\Requests\Decisions\StoreDecisionRuleRequest;
use App\Http\Requests\Decisions\UpdateDecisionRuleRequest;
use App\Models\Team;
use Illuminate\Http\JsonResponse;

class DecisionRuleController extends Controller
{
    public function index(Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', DecisionRule::class);

        $rulesets = RuleSet::query()
            ->where(function ($q) use ($current_team) {
                $q->whereNull('team_id')->orWhere('team_id', $current_team->id);
            })
            ->with(['rules' => fn ($q) => $q->orderByDesc('priority')])
            ->get();

        return response()->json(['data' => $rulesets]);
    }

    public function store(StoreDecisionRuleRequest $request, Team $current_team): JsonResponse
    {
        $this->authorize('create', DecisionRule::class);

        $ruleset = RuleSet::query()
            ->where(function ($q) use ($current_team) {
                $q->whereNull('team_id')->orWhere('team_id', $current_team->id);
            })
            ->whereKey($request->integer('ruleset_id'))
            ->firstOrFail();

        $rule = DecisionRule::create([
            'team_id' => $ruleset->team_id ?? $current_team->id,
            'ruleset_id' => $ruleset->id,
            'code' => (string) $request->string('code'),
            'name' => (string) $request->string('name'),
            'description' => $request->input('description'),
            'scope' => (string) $request->string('scope'),
            'priority' => $request->integer('priority', 0),
            'conditions_json' => (array) $request->input('conditions_json'),
            'outcome_override' => $request->input('outcome_override'),
            'escalation_policy_id' => $request->input('escalation_policy_id'),
            'stop_processing' => (bool) $request->boolean('stop_processing'),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json(['data' => $rule], 201);
    }

    public function update(UpdateDecisionRuleRequest $request, Team $current_team, DecisionRule $rule): JsonResponse
    {
        $this->authorize('update', $rule);

        $rule->fill($request->validated());
        $rule->save();

        return response()->json(['data' => $rule->fresh()]);
    }

    public function destroy(Team $current_team, DecisionRule $rule): JsonResponse
    {
        $this->authorize('delete', $rule);

        $rule->is_active = false;
        $rule->save();

        return response()->json(['data' => $rule->fresh()]);
    }
}
