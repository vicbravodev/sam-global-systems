<?php

namespace App\Domains\Normalization\Actions;

use App\Domains\Normalization\Models\EventMappingRule;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use App\Support\SystemLog;

class ResolveEventSeverity
{
    /**
     * Resolve severity with cascade: mapping rule override -> type default -> medium fallback.
     */
    public function execute(EventMappingRule $rule, EventType $type): EventSeverity
    {
        if ($rule->mapped_severity_id) {
            $source = 'rule_override';
            $severity = $rule->mappedSeverity;
        } elseif ($type->default_severity_id) {
            $source = 'type_default';
            $severity = $type->defaultSeverity;
        } else {
            $source = 'medium_fallback';
            $severity = EventSeverity::where('code', 'medium')->firstOrFail();
        }

        SystemLog::ok(
            'normalization.severity.resolved',
            input: ['mapping_rule_id' => $rule->id, 'event_type_code' => $type->code],
            calc: ['severity_source' => $source],
            result: ['severity_code' => $severity->code],
        );

        return $severity;
    }
}
