<?php

namespace App\Domains\Audit\Actions;

use App\Domains\Audit\Models\DomainEventLog;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Persists a single domain event into `domain_event_logs`. Called by the
 * wildcard listener after the event has been classified.
 */
class StoreDomainEvent
{
    /**
     * @param  array<string, mixed>  $payloadJson
     */
    public function execute(
        string $eventName,
        ?int $teamId,
        ?string $aggregateType,
        ?int $aggregateId,
        array $payloadJson,
        ?string $correlationId = null,
        ?string $causationId = null,
        ?DateTimeInterface $occurredAt = null,
    ): DomainEventLog {
        $log = new DomainEventLog;
        $log->team_id = $teamId;
        $log->event_name = $eventName;
        $log->aggregate_type = $aggregateType;
        $log->aggregate_id = $aggregateId;
        $log->payload_json = $payloadJson;
        $log->occurred_at = $occurredAt !== null ? CarbonImmutable::instance($occurredAt) : CarbonImmutable::now();
        $log->correlation_id = $correlationId;
        $log->causation_id = $causationId;
        // Un `teamId` null es un evento de plataforma: sin esto el
        // autollenado de BelongsToTenant lo sellaría con el tenant activo.
        TenantContext::for($teamId, fn () => $log->save());

        return $log;
    }
}
