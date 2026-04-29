<?php

namespace App\Domains\Audit\Jobs;

use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Actions\StoreDomainEvent;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Persists a single auditable event captured by the wildcard listener.
 * Runs on the `audit` queue (supervisor-low). Failures are logged but
 * do NOT propagate further events (no recursion into the audit listener).
 *
 * Idempotency: `(team_id, signature)` is unique on `audit_logs`. The
 * underlying `RecordAuditEntry` action handles the race-condition.
 */
class WriteAuditLogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    /**
     * @param  array<string, mixed>  $payloadJson
     */
    public function __construct(
        public readonly string $eventName,
        public readonly string $action,
        public readonly string $category,
        public readonly ?int $teamId,
        public readonly ?string $aggregateType,
        public readonly ?int $aggregateId,
        public readonly array $payloadJson,
        public readonly string $signature,
        public readonly ?string $correlationId = null,
        public readonly ?string $causationId = null,
        public readonly string $occurredAt = '',
    ) {
        $this->onQueue(config('audit.queue', 'audit'));
    }

    public function handle(
        StoreDomainEvent $storeDomainEvent,
        RecordAuditEntry $recordAuditEntry,
    ): void {
        $occurred = $this->occurredAt !== ''
            ? Carbon::parse($this->occurredAt)
            : now();

        // 1. Persist the raw domain event (wider, debugging-oriented).
        $storeDomainEvent->execute(
            eventName: $this->eventName,
            teamId: $this->teamId,
            aggregateType: $this->aggregateType,
            aggregateId: $this->aggregateId,
            payloadJson: $this->payloadJson,
            correlationId: $this->correlationId,
            causationId: $this->causationId,
            occurredAt: $occurred,
        );

        // 2. Promote to a structured audit log entry (user-facing trail).
        $recordAuditEntry->execute(
            actorType: AuditActorType::System,
            actorId: null,
            action: $this->action,
            category: AuditCategory::from($this->category),
            entityType: $this->aggregateType ?? $this->eventName,
            entityId: $this->aggregateId,
            summary: $this->buildSummary(),
            teamId: $this->teamId,
            metadata: $this->payloadJson,
            sourceType: 'domain_event',
            sourceReferenceId: $this->eventName,
            signature: $this->signature,
            occurredAt: $occurred,
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('WriteAuditLogJob failed', [
            'event_name' => $this->eventName,
            'team_id' => $this->teamId,
            'signature' => $this->signature,
            'error' => $exception->getMessage(),
        ]);
    }

    private function buildSummary(): string
    {
        $aggregate = $this->aggregateType
            ? sprintf(' on %s#%s', class_basename($this->aggregateType), $this->aggregateId ?? '?')
            : '';

        return sprintf('%s%s', $this->action, $aggregate);
    }

    /**
     * Allow the unique-constraint catch in `RecordAuditEntry` to suppress
     * duplicate writes silently — but if the row truly exists already,
     * there is no work left for this job.
     */
    public function uniqueId(): string
    {
        return $this->signature;
    }
}
