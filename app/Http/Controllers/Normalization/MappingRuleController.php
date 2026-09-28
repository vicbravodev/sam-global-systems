<?php

namespace App\Http\Controllers\Normalization;

use App\Domains\Normalization\Models\EventMappingRule;
use App\Http\Controllers\Controller;
use App\Http\Requests\Normalization\StoreMappingRuleRequest;
use App\Http\Requests\Normalization\UpdateMappingRuleRequest;
use App\Models\Team;
use App\Support\Http\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Las reglas de mapeo son globales (sin team_id): cualquier miembro puede
 * leerlas, pero sólo el super-admin puede mutarlas (EventMappingRulePolicy).
 */
class MappingRuleController extends Controller
{
    public function index(Request $request, Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', EventMappingRule::class);

        $query = EventMappingRule::query()
            ->with(['provider', 'mappedEventType', 'mappedCategory', 'mappedSeverity'])
            ->orderByDesc('priority');

        if ($request->filled('provider_id')) {
            $query->where('provider_id', $request->input('provider_id'));
        }

        $rules = $query->paginate(PerPage::from($request, 50));

        return response()->json($rules);
    }

    public function store(StoreMappingRuleRequest $request, Team $current_team): JsonResponse
    {
        $this->authorize('create', EventMappingRule::class);

        $rule = EventMappingRule::create($request->validated());

        return response()->json($rule->load(['provider', 'mappedEventType']), 201);
    }

    public function update(UpdateMappingRuleRequest $request, Team $current_team, EventMappingRule $mappingRule): JsonResponse
    {
        $this->authorize('update', $mappingRule);

        $mappingRule->update($request->validated());

        return response()->json($mappingRule->load(['provider', 'mappedEventType']));
    }

    public function destroy(Team $current_team, EventMappingRule $mappingRule): JsonResponse
    {
        $this->authorize('delete', $mappingRule);

        $mappingRule->delete();

        return response()->json(null, 204);
    }
}
