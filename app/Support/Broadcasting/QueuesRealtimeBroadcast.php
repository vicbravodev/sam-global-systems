<?php

namespace App\Support\Broadcasting;

/**
 * Delivery settings shared by every queued socket broadcast.
 *
 * - `broadcasts` queue: its own Horizon pool (supervisor-realtime), so a
 *   backlog in ingestion, audit or analytics never delays what the operator
 *   sees on screen. Without it every broadcast fell on `default`, the
 *   single-process low-priority pool.
 * - after commit: a broadcast emitted inside a transaction waits for it, so
 *   the client never reloads before the row it is told about is visible (or
 *   is told about a row that was rolled back).
 *
 * Pair it with `ShouldRescue` on the event: a socket message is best-effort
 * and must never fail the domain action that emitted it.
 */
trait QueuesRealtimeBroadcast
{
    public string $broadcastQueue = 'broadcasts';

    public bool $afterCommit = true;
}
