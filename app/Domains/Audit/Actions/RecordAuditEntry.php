<?php

namespace App\Domains\Audit\Actions;

use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Audit\Models\AuditLog;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Synchronous append of an audit_logs row. Used by domain modules that
 * want explicit audit entries (e.g. role grants, credential rotations,
 * AI overrides) without going through an event.
 *
 * Idempotency: when `$signature` is provided and already exists for the
 * same `team_id`, the call is a no-op.
 */
class RecordAuditEntry
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function execute(
        AuditActorType $actorType,
        ?int $actorId,
        string $action,
        AuditCategory $category,
        string $entityType,
        ?int $entityId,
        string $summary,
        ?int $teamId = null,
        ?array $metadata = null,
        ?string $sourceType = null,
        ?string $sourceReferenceId = null,
        ?string $signature = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?DateTimeInterface $occurredAt = null,
    ): ?AuditLog {
        $signature ??= $this->buildSignature(
            $action,
            $entityType,
            $entityId,
            $teamId,
            $sourceReferenceId,
        );

        $occurredAt = $occurredAt ? Carbon::instance($occurredAt) : Carbon::now();

        if ($teamId !== null) {
            $existing = AuditLog::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('signature', $signature)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        try {
            $log = new AuditLog;
            $log->team_id = $teamId;
            $log->actor_type = $actorType;
            $log->actor_id = $actorId;
            $log->action = $action;
            $log->category = $category;
            $log->entity_type = $entityType;
            $log->entity_id = $entityId;
            $log->source_type = $sourceType;
            $log->source_reference_id = $sourceReferenceId;
            $log->signature = $signature;
            $log->summary = $summary;
            $log->metadata_json = $metadata ?? [];
            $log->ip_address = $ipAddress;
            $log->user_agent = $userAgent;
            $log->occurred_at = $occurredAt;
            $log->save();

            return $log;
        } catch (UniqueConstraintViolationException) {
            // Another process won the race on (team_id, signature). Treat
            // as idempotent success and return the row that already exists.
            return AuditLog::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('signature', $signature)
                ->first();
        }
    }

    private function buildSignature(
        string $action,
        string $entityType,
        ?int $entityId,
        ?int $teamId,
        ?string $sourceReferenceId,
    ): string {
        $parts = [
            $action,
            $entityType,
            $entityId ?? 'na',
            $teamId ?? 'system',
            $sourceReferenceId ?? 'na',
        ];

        return 'audit:'.hash('sha256', implode('|', $parts));
    }
}
