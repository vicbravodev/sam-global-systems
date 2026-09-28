<?php

namespace Database\Seeders\Showcase;

use Database\Seeders\Showcase\Support\ShowcaseContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Un paso del showcase = un dominio. Corre SIEMPRE dentro del
 * `TenantContext` del team del contexto (lo pone {@see ShowcaseSeeder}), así
 * que las lecturas de modelos con `BelongsToTenant` ya salen filtradas; aun
 * así cada escritura pone su `team_id` explícito.
 *
 * Contrato de idempotencia: un paso nunca borra ni modifica filas que no
 * creó el showcase, y una segunda corrida no duplica nada (cada paso
 * documenta su marcador).
 */
abstract class ShowcaseStep extends Seeder
{
    public function __construct(protected ShowcaseContext $ctx) {}

    abstract public function run(): void;

    /**
     * Inserción masiva para tablas hijas voluminosas (trazas GPS, telemetría,
     * KPIs…). Los arrays anidados se serializan a JSON; `created_at` /
     * `updated_at` se rellenan si la fila no los trae.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function bulkInsert(string $table, array $rows, bool $timestamps = true): void
    {
        if ($rows === []) {
            return;
        }

        $now = $this->ctx->now;

        $rows = array_map(function (array $row) use ($now, $timestamps): array {
            foreach ($row as $column => $value) {
                if (is_array($value)) {
                    $row[$column] = json_encode($value, JSON_UNESCAPED_UNICODE);
                } elseif ($value instanceof \BackedEnum) {
                    $row[$column] = $value->value;
                }
            }

            if ($timestamps) {
                $row['created_at'] ??= $now;
                $row['updated_at'] ??= $now;
            }

            return $row;
        }, $rows);

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table($table)->insert($chunk);
        }

        $this->ctx->count($table, count($rows));
    }
}
