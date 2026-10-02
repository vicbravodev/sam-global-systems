<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Borra en lotes las filas que selecciona una query, para que una purga con
 * mucho rezago nunca sostenga una transacción larga ni un bloqueo de tabla.
 *
 * Cada vuelta lee hasta `$chunk` claves y borra exactamente esas (también con
 * los filtros de la query original): sirve igual en PostgreSQL, que no admite
 * `DELETE ... LIMIT`, y en SQLite. El tenant lo decide quien llama: un
 * recorrido de plataforma envuelve la llamada en `TenantContext::withoutTenant`.
 */
final class ChunkedPurge
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return array{removed: int, batches: int}
     */
    public static function run(Builder $query, int $chunk): array
    {
        $key = $query->getModel()->getKeyName();
        $removed = 0;
        $batches = 0;

        do {
            $ids = (clone $query)->limit($chunk)->pluck($key)->all();

            $deleted = $ids === [] ? 0 : (int) (clone $query)->whereKey($ids)->delete();
            $removed += $deleted;

            if ($deleted > 0) {
                $batches++;
            }
        } while ($deleted > 0);

        return ['removed' => $removed, 'batches' => $batches];
    }
}
