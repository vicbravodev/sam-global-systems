<?php

namespace App\Domains\Audit\Jobs;

use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Actions\StoreDomainEvent;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Support\JobFailureReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Persists a single auditable event captured by the wildcard listener.
 * Runs on the `audit` queue (supervisor-low). Failures are logged but
 * do NOT propagate further events (no recursion into the audit listener).
 *
 * Idempotency: `(team_id, signature)` is unique on `audit_logs`. The
 * underlying `RecordAuditEntry` action handles the race-condition, and
 * both writes share one transaction so a retry never duplicates the
 * `domain_event_logs` row.
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

        // Los dos pasos van juntos: domain_event_logs es un insert simple, sin
        // clave de idempotencia, así que si el paso 2 fallara después de
        // escribir el 1, cada reintento (tries = 3) duplicaría el evento. Con
        // la transacción, un intento fallido no deja nada y el reintento
        // empieza de cero; RecordAuditEntry abre su propio savepoint.
        DB::transaction(function () use ($storeDomainEvent, $recordAuditEntry, $occurred): void {
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
        });
    }

    public function failed(\Throwable $exception): void
    {
        JobFailureReporter::report(static::class, $exception, ['event_name' => $this->eventName, 'team_id' => $this->teamId]);
    }

    private function buildSummary(): string
    {
        // aggregateType es un FQCN de modelo: nunca '0', basta excluir null y ''.
        $aggregate = $this->aggregateType !== null && $this->aggregateType !== ''
            ? sprintf(' on %s#%s', class_basename($this->aggregateType), $this->aggregateId ?? '?')
            : '';

        return sprintf('%s%s', $this->action, $aggregate);
    }
}
