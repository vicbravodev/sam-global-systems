<?php

namespace App\Domains\Notifications\Jobs;

use App\Domains\Notifications\Models\NotificationReplyToken;
use App\Support\ChunkedPurge;
use App\Support\JobFailureReporter;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Retención de `notification_reply_tokens` (`pipeline.retention.reply_tokens_days`).
 *
 * Un token vive 24 h (`IssueNotificationReplyToken::TTL_HOURS`); vencido ya no
 * resuelve ninguna respuesta (`ProcessInboundReply` sólo acepta vigentes) y lo
 * que decidió ya quedó en el incidente y en `audit_logs` (`incident.reply.*`).
 * Guarda el teléfono del destinatario, así que conservarlo de más es dato
 * personal sin uso. Se borra pasados
 * `reply_tokens_days` días DESDE que venció, consumido o no.
 *
 * Recorrido de plataforma: cruza tenants a propósito y sólo registra conteos.
 */
class PurgeExpiredReplyTokensJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TABLE = 'notification_reply_tokens';

    private const CHUNK = 1000;

    public function __construct(
        public readonly ?int $retentionDays = null,
    ) {
        $this->onQueue('sync');
    }

    public function handle(): int
    {
        $days = $this->retentionDays ?? (int) config('pipeline.retention.reply_tokens_days', 30);
        $source = $this->retentionDays !== null ? 'argument' : 'config';

        if ($days < 1) {
            SystemLog::skipped('notifications.purge.completed', reason: 'disabled', input: ['table' => self::TABLE], calc: [
                'retention_days' => $days,
                'retention_source' => $source,
            ]);

            return 0;
        }

        $cutoff = now()->subDays($days);

        $outcome = TenantContext::withoutTenant(fn (): array => ChunkedPurge::run(
            NotificationReplyToken::query()->where('expires_at', '<', $cutoff),
            self::CHUNK,
        ));

        SystemLog::ok('notifications.purge.completed', input: ['table' => self::TABLE], calc: [
            'retention_days' => $days,
            'retention_source' => $source,
            'cutoff' => $cutoff->toIso8601String(),
            'chunk_size' => self::CHUNK,
        ], result: [
            'removed_count' => $outcome['removed'],
            'batches_count' => $outcome['batches'],
        ]);

        return $outcome['removed'];
    }

    public function failed(Throwable $e): void
    {
        JobFailureReporter::report(static::class, $e);
    }
}
