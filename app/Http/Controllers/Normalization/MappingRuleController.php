<?php

namespace App\Http\Controllers\Normalization;

use App\Domains\Normalization\Models\EventMappingRule;
use App\Http\Controllers\Controller;
use App\Http\Requests\Normalization\StoreMappingRuleRequest;
use App\Http\Requests\Normalization\UpdateMappingRuleRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MappingRuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = EventMappingRule::query()
            ->with(['provider', 'mappedEventType', 'mappedCategory', 'mappedSeverity'])
            ->orderByDesc('priority');

        if ($request->filled('provider_id')) {
            $query->where('provider_id', $request->input('provider_id'));
        }

        $rules = $query->paginate($request->integer('per_page', 50));

        return response()->json($rules);
    }

    public function store(StoreMappingRuleRequest $request): JsonResponse
    {
        $rule = EventMappingRule::create($request->validated());

        return response()->json($rule->load(['provider', 'mappedEventType']), 201);
    }

    public function update(UpdateMappingRuleRequest $request, EventMappingRule $mappingRule): JsonResponse
    {
        $mappingRule->update($request->validated());

        return response()->json($mappingRule->load(['provider', 'mappedEventType']));
    }

    public function destroy(EventMappingRule $mappingRule): JsonResponse
    {
        $mappingRule->delete();

        return response()->json(null, 204);
    }
}
