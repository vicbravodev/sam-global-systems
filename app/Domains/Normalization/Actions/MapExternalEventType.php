<?php

namespace App\Domains\Normalization\Actions;

use App\Domains\Normalization\Models\EventMappingRule;
use Illuminate\Support\Arr;

class MapExternalEventType
{
    /**
     * Find the highest-priority active mapping rule for the given provider and external event type.
     *
     * @param  array<string, mixed>|null  $payload  Raw event payload for condition evaluation
     */
    public function execute(
        int $providerId,
        string $externalEventType,
        ?array $payload = null,
    ): ?EventMappingRule {
        $candidates = EventMappingRule::query()
            ->active()
            ->where('provider_id', $providerId)
            ->where('external_event_type', $externalEventType)
            ->orderByDesc('priority')
            ->get();

        foreach ($candidates as $rule) {
            if ($this->matchesConditions($rule, $payload)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Evaluate all conditions in external_conditions_json as AND logic
     * against the raw payload using dot-notation path matching.
     */
    private function matchesConditions(EventMappingRule $rule, ?array $payload): bool
    {
        $conditions = $rule->external_conditions_json;

        if (empty($conditions)) {
            return true;
        }

        if ($payload === null) {
            return false;
        }

        foreach ($conditions as $dotPath => $expectedValue) {
            $actualValue = Arr::get($payload, $dotPath);

            if ($actualValue !== $expectedValue) {
                return false;
            }
        }

        return true;
    }
}
