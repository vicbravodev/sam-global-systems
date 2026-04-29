<?php

namespace App\Domains\Audit\Listeners;

use App\Domains\Audit\AuditServiceProvider;
use App\Domains\Audit\Contracts\AuditableEventClassifier;
use App\Domains\Audit\Jobs\WriteAuditLogJob;
use Illuminate\Support\Facades\Log;
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
            $this->logQuietly('AuditAnyDomainEvent classifier threw', $eventName, $exception);

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
            $this->logQuietly('AuditAnyDomainEvent dispatch failed', $eventName, $exception);
        }
    }

    private function logQuietly(string $message, string $eventName, Throwable $exception): void
    {
        try {
            Log::warning($message, [
                'event' => $eventName,
                'error' => $exception->getMessage(),
            ]);
        } catch (Throwable) {
            // Last-ditch silent swallow: the audit subsystem must NEVER
            // surface its own failures into the calling pipeline.
        }
    }
}
