<?php

namespace App\Http\Controllers\Decisions;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Decisions\Models\DecisionRule;
use App\Domains\Decisions\Support\ConditionExplainer;
use App\Domains\Decisions\Support\DecisionFactsBuilder;
use App\Domains\Ingestion\Models\RawEvent;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Support\Conditions\ValidConditionTree;
use App\Support\Conditions\ValidFlatConditions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Rule tester ("¿coincidiría?"): evaluates draft conditions against the
 * tenant's most recent real evaluation/event so the operator can see the
 * verdict — leaf by leaf — before saving a rule. Read-only: nothing is
 * persisted.
 */
class RuleTestController extends Controller
{
    public function testDecision(
        Request $request,
        Team $current_team,
        DecisionFactsBuilder $factsBuilder,
        ConditionExplainer $explainer,
    ): JsonResponse {
        $this->authorize('viewAny', DecisionRule::class);

        $validated = $request->validate([
            'conditions_json' => ['required', 'array', new ValidConditionTree],
        ]);

        $eval = AIEventEvaluation::withoutGlobalScopes()
            ->where('team_id', $current_team->id)
            ->orderByDesc('id')
            ->first();

        if ($eval === null) {
            return response()->json(['result' => 'no_events']);
        }

        $context = EventContextSnapshot::withoutGlobalScopes()
            ->where('normalized_event_id', $eval->normalized_event_id)
            ->first();

        $facts = $factsBuilder->build($eval, $context);
        $explanation = $explainer->explain($validated['conditions_json'], $facts);

        return response()->json([
            'result' => $explanation['matched'] ? 'match' : 'no_match',
            'checks' => $explanation['checks'],
            'event' => [
                'evaluationId' => (int) $eval->id,
                'classification' => $eval->classification?->value,
                'evaluatedAt' => $eval->evaluated_at?->toIso8601String(),
            ],
        ]);
    }

    public function testMapping(Request $request, Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', DecisionRule::class);

        $validated = $request->validate([
            'external_conditions_json' => ['required', 'array', new ValidFlatConditions],
        ]);

        $rawEvent = RawEvent::withoutGlobalScopes()
            ->where('team_id', $current_team->id)
            ->orderByDesc('id')
            ->first();

        if ($rawEvent === null) {
            return response()->json(['result' => 'no_events']);
        }

        $payload = (array) ($rawEvent->payload_json ?? []);
        $checks = [];
        $matched = true;

        foreach ($validated['external_conditions_json'] as $dotPath => $expected) {
            $actual = Arr::get($payload, $dotPath);
            $passed = $actual === $expected;
            $matched = $matched && $passed;

            $checks[] = [
                'field' => $dotPath,
                'operator' => 'eq',
                'expected' => $expected,
                'actual' => $actual,
                'passed' => $passed,
            ];
        }

        return response()->json([
            'result' => $matched ? 'match' : 'no_match',
            'checks' => $checks,
            'event' => [
                'rawEventId' => (int) $rawEvent->id,
                'receivedAt' => $rawEvent->created_at?->toIso8601String(),
            ],
        ]);
    }
}
