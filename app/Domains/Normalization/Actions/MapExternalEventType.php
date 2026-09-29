<?php

namespace App\Domains\Normalization\Actions;

use App\Domains\Normalization\Models\EventMappingRule;
use App\Support\SystemLog;
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
            ->with('mappedEventType')
            ->active()
            ->where('provider_id', $providerId)
            ->where('external_event_type', $externalEventType)
            ->orderByDesc('priority')
            ->get();

        $rejected = [];
        $evaluated = 0;

        foreach ($candidates as $rule) {
            $evaluated++;
            $failedPath = $this->matchesConditions($rule, $payload);

            if ($failedPath === null) {
                SystemLog::ok(
                    'normalization.type.mapped',
                    input: ['provider_id' => $providerId, 'external_event_type' => $externalEventType],
                    calc: ['candidates' => $candidates->count(), 'evaluated' => $evaluated, 'rejected' => $rejected],
                    result: [
                        'mapping_rule_id' => $rule->id,
                        'event_type_code' => $rule->mappedEventType?->code,
                        'priority' => $rule->priority,
                    ],
                );

                return $rule;
            }

            $rejected[] = ['mapping_rule_id' => $rule->id, 'failed_path' => $failedPath];
        }

        if ($candidates->isEmpty()) {
            SystemLog::skipped(
                'normalization.type.unmapped',
                reason: 'no_rule_for_type',
                input: ['provider_id' => $providerId, 'external_event_type' => $externalEventType],
                calc: ['candidates' => 0],
            );
        } else {
            SystemLog::skipped(
                'normalization.type.unmapped',
                reason: 'conditions_not_met',
                input: ['provider_id' => $providerId, 'external_event_type' => $externalEventType],
                calc: ['candidates' => $candidates->count(), 'rejected' => $rejected],
            );
        }

        return null;
    }

    /**
     * Evaluate all conditions in external_conditions_json as AND logic
     * against the raw payload using dot-notation path matching.
     *
     * @return string|null null when every condition holds, else the first failed path
     *                     (`*` when there is no payload to evaluate against)
     */
    private function matchesConditions(EventMappingRule $rule, ?array $payload): ?string
    {
        $conditions = $rule->external_conditions_json;

        if (empty($conditions)) {
            return null;
        }

        if ($payload === null) {
            return '*';
        }

        foreach ($conditions as $dotPath => $expectedValue) {
            $actualValue = Arr::get($payload, $dotPath);

            if ($actualValue !== $expectedValue) {
                return (string) $dotPath;
            }
        }

        return null;
    }
}
