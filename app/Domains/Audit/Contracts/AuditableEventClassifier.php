<?php

namespace App\Domains\Audit\Contracts;

use App\Domains\Audit\Data\AuditableEventDescriptor;

/**
 * Classifies a dispatched event payload to decide whether the wildcard
 * audit listener should persist it, and if so what category/team_id to
 * record. Returning null means: ignore this event.
 */
interface AuditableEventClassifier
{
    /**
     * @param  string  $eventName  FQCN of the dispatched event
     * @param  array<int, mixed>  $payload  positional event payload
     */
    public function classify(string $eventName, array $payload): ?AuditableEventDescriptor;
}
