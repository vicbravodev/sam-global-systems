<?php

namespace App\Domains\AI\Commands;

use App\Domains\AI\Queries\ClefShadowComparisonQuery;
use Illuminate\Console\Command;

/**
 * Reporte de la medición Clef vs GPT. La métrica de seguridad es el recall
 * de eventos reales: Clef no puede dejar pasar más reales que GPT.
 */
class ClefReportCommand extends Command
{
    protected $signature = 'ai:clef-report
        {--team= : Sólo este team id}
        {--days=30 : Ventana en días}
        {--by-type : Desglosar por tipo de evento}';

    protected $description = 'Compara GPT, Clef y Clef-flash contra GPT y contra el veredicto humano';

    public function handle(ClefShadowComparisonQuery $query): int
    {
        $team = $this->option('team') !== null ? (int) $this->option('team') : null;
        $report = $query->execute($team, now()->subDays(max(1, (int) $this->option('days'))), (bool) $this->option('by-type'));
        $pct = fn (mixed $v): string => is_numeric($v) ? number_format((float) $v * 100, 1).' %' : '—';

        foreach ($report as $bucket => $models) {
            $this->newLine();
            $this->line('<options=bold>'.($bucket === 'all' ? 'Todos los eventos' : 'Tipo: '.$bucket).'</>');

            $rows = [];

            foreach ($models as $model => $m) {
                $rows[] = [
                    $model,
                    $m['n'],
                    $pct($m['agree_with_gpt']),
                    $pct($m['recall_real']).' ('.$m['real_n'].')',
                    $pct($m['discard_correct']).' ('.$m['fp_n'].')',
                    $pct($m['strict_accuracy']),
                    $m['brier'] ?? '—',
                    'US$'.number_format((float) $m['cost_total'], 4),
                    $m['latency_p50'] ?? '—',
                    $m['latency_p95'] ?? '—',
                    $m['failed'],
                ];
            }

            $this->table(
                ['Modelo', 'n', 'Concuerda con GPT', 'Recall reales (n)', 'Descarte FP (n)', 'Acierto', 'Brier', 'Costo total', 'p50 ms', 'p95 ms', 'Fallidas'],
                $rows,
            );

            $minVerdicts = min(array_map(fn (array $m): int => (int) $m['verdict_n'], $models));

            if ($minVerdicts < ClefShadowComparisonQuery::MIN_SAMPLE) {
                $this->warn(sprintf(
                    'Muestra insuficiente: %d veredictos (mínimo %d para juzgar; criterio de paso: 300).',
                    $minVerdicts,
                    ClefShadowComparisonQuery::MIN_SAMPLE,
                ));
            }
        }

        return self::SUCCESS;
    }
}
