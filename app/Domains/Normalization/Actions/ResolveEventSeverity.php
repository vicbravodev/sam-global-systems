<?php

namespace App\Domains\Normalization\Actions;

use App\Domains\Normalization\Models\EventMappingRule;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;

class ResolveEventSeverity
{
    /**
     * Resolve severity with cascade: mapping rule override -> type default -> medium fallback.
     */
    public function execute(EventMappingRule $rule, EventType $type): EventSeverity
    {
        if ($rule->mapped_severity_id) {
            return $rule->mappedSeverity;
        }

        if ($type->default_severity_id) {
            return $type->defaultSeverity;
        }

        return EventSeverity::where('code', 'medium')->firstOrFail();
    }
}
