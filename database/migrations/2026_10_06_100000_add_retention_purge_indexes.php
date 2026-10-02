<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índices para las purgas nocturnas de retención (config `pipeline.retention`).
 * Sin ellos, cada pasada recorre la tabla entera: `webhook_events` sólo tenía
 * `(team_id, received_at)`, inútil para un corte de plataforma por fecha.
 *
 * Sólo aditivo. En PostgreSQL cada índice se construye CONCURRENTLY (sin
 * bloquear escrituras), que no puede ir dentro de una transacción.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * name => [table, columns]
     *
     * @return array<string, array{0: string, 1: list<string>}>
     */
    private function indexes(): array
    {
        return [
            'webhook_events_received_at_index' => ['webhook_events', ['received_at']],
            'notification_reply_tokens_expires_at_index' => ['notification_reply_tokens', ['expires_at']],
            'integration_sync_jobs_created_at_index' => ['integration_sync_jobs', ['created_at']],
        ];
    }

    public function up(): void
    {
        $concurrently = DB::getDriverName() === 'pgsql' ? 'CONCURRENTLY ' : '';

        foreach ($this->indexes() as $name => [$table, $columns]) {
            $columnList = implode(', ', array_map(fn (string $column) => '"'.$column.'"', $columns));

            DB::statement(sprintf(
                'CREATE INDEX %sIF NOT EXISTS "%s" ON "%s" (%s)',
                $concurrently,
                $name,
                $table,
                $columnList,
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
