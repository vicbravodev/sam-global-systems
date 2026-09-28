<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Unique keys the code already relies on but the schema did not enforce, so
 * concurrent writers could create duplicates. (`event_sources` is left out on
 * purpose: one source per integration is a valid shape, since per-integration
 * event counts key on `tenant_integration_id`; StoreRawEvent serialises its
 * own resolution with a lock.)
 *
 * - invoice_snapshots: one live invoice per tenant and period (admin trigger
 *   vs scheduled run). Partial: a voided invoice may sit next to its reissue.
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
     * name => [table, columns, nulls not distinct (pgsql only), partial WHERE]
     *
     * @return array<string, array{0: string, 1: list<string>, 2: bool, 3?: string}>
     */
    private function indexes(): array
    {
        $pgsql = DB::getDriverName() === 'pgsql';

        $indexes = [
            'invoice_snapshots_team_period_unique' => ['invoice_snapshots', ['team_id', 'period_start', 'period_end'], false, "status <> 'void'"],
        ];

        if ($pgsql) {
            $indexes += [
                'kpi_records_team_metric_period_dim_nnd_unique' => ['kpi_records', ['team_id', 'kpi_code', 'period_type', 'period_start', 'dimension_type', 'dimension_reference'], true],
                'analytics_snapshots_team_type_entity_period_nnd_unique' => ['analytics_snapshots', ['team_id', 'snapshot_type', 'entity_type', 'entity_id', 'period_start'], true],
            ];
        }

        return $indexes;
    }

    public function up(): void
    {
        $concurrently = DB::getDriverName() === 'pgsql' ? 'CONCURRENTLY ' : '';

        foreach ($this->indexes() as $name => $index) {
            [$table, $columns, $nullsNotDistinct] = $index;
            $where = $index[3] ?? null;

            $duplicates = DB::table($table)
                ->when($where !== null, fn ($query) => $query->whereRaw($where))
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
                'CREATE UNIQUE INDEX %sIF NOT EXISTS "%s" ON "%s" (%s)%s%s',
                $concurrently,
                $name,
                $table,
                implode(', ', array_map(fn (string $column) => '"'.$column.'"', $columns)),
                $nullsNotDistinct ? ' NULLS NOT DISTINCT' : '',
                $where !== null ? ' WHERE '.$where : '',
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
