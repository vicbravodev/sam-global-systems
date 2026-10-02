<?php

namespace App\Domains\Ingestion\Jobs;

use App\Support\JobFailureReporter;
use App\Support\SystemLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\PrunableFailedJobProvider;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Retención de `failed_jobs` (`pipeline.retention.failed_jobs_days`).
 *
 * Cada job que agota sus intentos deja ahí su payload completo; una tormenta
 * (un deploy con autoloader viejo dejó 786 de golpe) la infla sin límite y
 * nada la poda. Horizon guarda su propia copia 7 días para reintentar desde
 * el panel; pasada la ventana, un fallido ya no se va a reintentar.
 *
 * Vive en Ingestion porque los fallidos que importan son los del pipeline,
 * pero la tabla es de plataforma (sin tenant): usa el `PrunableFailedJobProvider`
 * del framework, que borra en lotes de 1000.
 */
class PurgeOldFailedJobsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TABLE = 'failed_jobs';

    public function __construct(
        public readonly ?int $retentionDays = null,
    ) {
        $this->onQueue('sync');
    }

    public function handle(FailedJobProviderInterface $failer): int
    {
        $days = $this->retentionDays ?? (int) config('pipeline.retention.failed_jobs_days', 30);
        $source = $this->retentionDays !== null ? 'argument' : 'config';

        if ($days < 1) {
            SystemLog::skipped('queue.purge.completed', reason: 'disabled', input: ['table' => self::TABLE], calc: [
                'retention_days' => $days,
                'retention_source' => $source,
            ]);

            return 0;
        }

        if (! $failer instanceof PrunableFailedJobProvider) {
            SystemLog::skipped('queue.purge.completed', reason: 'provider_not_prunable', input: ['table' => self::TABLE], calc: [
                'retention_days' => $days,
                'retention_source' => $source,
                'provider' => class_basename($failer),
            ]);

            return 0;
        }

        $cutoff = now()->subDays($days);
        $removed = $failer->prune($cutoff);

        SystemLog::ok('queue.purge.completed', input: ['table' => self::TABLE], calc: [
            'retention_days' => $days,
            'retention_source' => $source,
            'cutoff' => $cutoff->toIso8601String(),
        ], result: [
            'removed_count' => $removed,
        ]);

        return $removed;
    }

    public function failed(Throwable $e): void
    {
        JobFailureReporter::report(static::class, $e);
    }
}
