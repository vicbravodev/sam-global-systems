<?php

namespace App\Http\Controllers\Normalization;

use App\Domains\Normalization\Models\EventMappingRule;
use App\Http\Controllers\Controller;
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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider_id' => ['required', 'exists:integration_providers,id'],
            'external_event_type' => ['required', 'string', 'max:255'],
            'external_conditions_json' => ['nullable', 'array'],
            'mapped_event_type_id' => ['required', 'exists:event_types,id'],
            'mapped_category_id' => ['nullable', 'exists:event_categories,id'],
            'mapped_severity_id' => ['nullable', 'exists:event_severities,id'],
            'priority' => ['integer', 'min:0', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        $rule = EventMappingRule::create($validated);

        return response()->json($rule->load(['provider', 'mappedEventType']), 201);
    }

    public function update(Request $request, EventMappingRule $mappingRule): JsonResponse
    {
        $validated = $request->validate([
            'external_event_type' => ['sometimes', 'string', 'max:255'],
            'external_conditions_json' => ['nullable', 'array'],
            'mapped_event_type_id' => ['sometimes', 'exists:event_types,id'],
            'mapped_category_id' => ['nullable', 'exists:event_categories,id'],
            'mapped_severity_id' => ['nullable', 'exists:event_severities,id'],
            'priority' => ['integer', 'min:0', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        $mappingRule->update($validated);

        return response()->json($mappingRule->load(['provider', 'mappedEventType']));
    }

    public function destroy(EventMappingRule $mappingRule): JsonResponse
    {
        $mappingRule->delete();

        return response()->json(null, 204);
    }
}
