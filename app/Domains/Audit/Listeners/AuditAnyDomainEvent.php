<?php

namespace App\Domains\Audit\Listeners;

use App\Domains\Audit\AuditServiceProvider;
use App\Domains\Audit\Contracts\AuditableEventClassifier;
use App\Domains\Audit\Jobs\WriteAuditLogJob;
use App\Support\SystemLog;
use Throwable;

/**
 * Wildcard listener subscribed to `*` in {@see AuditServiceProvider}.
 *
 * IMPORTANT: this listener MUST NOT dispatch any domain event of its own,
 * otherwise the `*` wildcard would re-trigger and recurse infinitely.
 * The job dispatch below uses the queue, never the event bus.
 */
class AuditAnyDomainEvent
{
    public function __construct(
        private readonly AuditableEventClassifier $classifier,
    ) {}

    /**
     * @param  array<int, mixed>  $payload
     */
    public function handle(string $eventName, array $payload): void
    {
        // Cheap fast-path: ignore framework noise before any reflection.
        if (! str_starts_with($eventName, 'App\\Domains\\')) {
            return;
        }

        try {
            $descriptor = $this->classifier->classify($eventName, $payload);
        } catch (Throwable $exception) {
            // Never let an audit-classification failure break the dispatcher
            // for the original event. Log silently and bail.
            $this->logQuietly('classifier_failed', $eventName, $exception);

            return;
        }

        if ($descriptor === null) {
            return;
        }

        $occurredAt = now()->toIso8601String();

        try {
            WriteAuditLogJob::dispatch(
                $descriptor->eventName,
                $descriptor->action,
                $descriptor->category->value,
                $descriptor->teamId,
                $descriptor->aggregateType,
                $descriptor->aggregateId,
                $descriptor->payloadJson,
                $descriptor->signature,
                null,
                null,
                $occurredAt,
            );
        } catch (Throwable $exception) {
            // Same principle: never propagate audit failures to the caller.
            $this->logQuietly('dispatch_failed', $eventName, $exception);
        }
    }

    private function logQuietly(string $reason, string $eventName, Throwable $exception): void
    {
        try {
            SystemLog::degraded('audit.domain_event.record_failed', reason: $reason, input: ['event_name' => $eventName], error: $exception);
        } catch (Throwable) {
            // Last-ditch silent swallow: the audit subsystem must NEVER
            // surface its own failures into the calling pipeline.
        }
    }
}
