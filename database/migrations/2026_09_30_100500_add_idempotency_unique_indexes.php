<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Unique keys the code already relies on but the schema did not enforce, so
 * concurrent writers could create duplicates:
 *
 * - invoice_snapshots: one invoice per tenant and period (admin trigger vs
 *   scheduled run).
 * - event_sources: one source per (tenant, provider, type); dedup keys are
 *   scoped by source, so a duplicated source silently splits dedup.
 * - kpi_records / analytics_snapshots: their existing uniques include
 *   nullable dimension columns, and PostgreSQL treats NULLs as distinct, so
 *   the rows written with NULL dimensions were never protected.
 *   `NULLS NOT DISTINCT` (PostgreSQL 15+) closes that.
 *
 * Additive only, built CONCURRENTLY on PostgreSQL. If a table already holds
 * duplicates the index is skipped with a warning instead of failing the
 * deploy; clean them up and re-run this migration's index by hand.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * name => [table, columns, nulls not distinct (pgsql only)]
     *
     * @return array<string, array{0: string, 1: list<string>, 2: bool}>
     */
    private function indexes(): array
    {
        $pgsql = DB::getDriverName() === 'pgsql';

        $indexes = [
            'invoice_snapshots_team_period_unique' => ['invoice_snapshots', ['team_id', 'period_start', 'period_end'], false],
        ];

        if ($pgsql) {
            $indexes += [
                'event_sources_team_provider_type_unique' => ['event_sources', ['team_id', 'provider_id', 'source_type'], true],
                'kpi_records_team_metric_period_dim_nnd_unique' => ['kpi_records', ['team_id', 'kpi_code', 'period_type', 'period_start', 'dimension_type', 'dimension_reference'], true],
                'analytics_snapshots_team_type_entity_period_nnd_unique' => ['analytics_snapshots', ['team_id', 'snapshot_type', 'entity_type', 'entity_id', 'period_start'], true],
            ];
        }

        return $indexes;
    }

    public function up(): void
    {
        $concurrently = DB::getDriverName() === 'pgsql' ? 'CONCURRENTLY ' : '';

        foreach ($this->indexes() as $name => [$table, $columns, $nullsNotDistinct]) {
            $duplicates = DB::table($table)
                ->select($columns)
                ->groupBy($columns)
                ->havingRaw('COUNT(*) > 1')
                ->limit(1)
                ->exists();

            if ($duplicates) {
                Log::warning("Skipping unique index {$name}: {$table} already has duplicate rows.");

                continue;
            }

            DB::statement(sprintf(
                'CREATE UNIQUE INDEX %sIF NOT EXISTS "%s" ON "%s" (%s)%s',
                $concurrently,
                $name,
                $table,
                implode(', ', array_map(fn (string $column) => '"'.$column.'"', $columns)),
                $nullsNotDistinct ? ' NULLS NOT DISTINCT' : '',
            ));
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->indexes()) as $name) {
            DB::statement(sprintf('DROP INDEX IF EXISTS "%s"', $name));
        }
    }
};
