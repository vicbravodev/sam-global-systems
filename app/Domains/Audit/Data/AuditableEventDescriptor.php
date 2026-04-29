<?php

namespace App\Domains\Audit\Data;

use App\Domains\Audit\Enums\AuditCategory;

/**
 * DTO describing how a single dispatched event should be persisted by
 * the audit wildcard listener.
 */
final class AuditableEventDescriptor
{
    /**
     * @param  array<string, mixed>  $payloadJson  serialized event payload
     */
    public function __construct(
        public readonly string $eventName,
        public readonly AuditCategory $category,
        public readonly string $action,
        public readonly ?int $teamId,
        public readonly ?string $aggregateType,
        public readonly ?int $aggregateId,
        public readonly array $payloadJson,
        public readonly string $signature,
    ) {}
}
