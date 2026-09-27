<?php

namespace Tests\Concerns;

use App\Models\Team;
use App\Support\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Utilidades para escribir el test de fuga que exige CLAUDE.md §2.1 punto 8.
 *
 * Los `*TenantIsolationTest` que ya existen prueban el scope global de Eloquent
 * con un usuario logueado — el caso que nunca falla. Las dos fugas reales del
 * repo (assets y normalización resolviendo por id de proveedor) pasaron por
 * delante de esos tests sin despeinarse, porque ocurrían en jobs y actions.
 *
 * Lo que hace falta probar es el camino real de la feature: ejecutarla como el
 * tenant B y comprobar que no leyó, vinculó ni modificó nada del tenant A.
 */
trait AssertsTenantIsolation
{
    /**
     * Ejecuta el callback dentro del tenant dado y falla si tocó datos de
     * cualquier otro tenant.
     *
     * Compara, antes y después, todas las filas de todas las tablas con
     * columna `team_id` que NO pertenecen a este tenant: si el callback creó,
     * modificó o borró alguna, hay fuga de escritura. Si además devuelve
     * modelos, verifica que todos son de este tenant (fuga de lectura).
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function assertNoTenantLeak(Team|int $tenant, Closure $callback): mixed
    {
        $teamId = $tenant instanceof Team ? $tenant->id : $tenant;

        $before = $this->foreignTenantRows($teamId);

        $result = TenantContext::for($teamId, $callback);

        $after = $this->foreignTenantRows($teamId);

        foreach ($after as $table => $rows) {
            $this->assertSame(
                $before[$table] ?? [],
                $rows,
                "Fuga cross-tenant: el código escribió en `{$table}` filas que no son del team {$teamId}.\n".
                "Filtra por team_id en esa escritura. Regla: CLAUDE.md §2.1.\n",
            );
        }

        $this->assertBelongsToTenant($teamId, $result);

        return $result;
    }

    /**
     * Falla si el resultado contiene algún modelo de otro tenant. Acepta un
     * modelo, una colección o un array; ignora lo que no sea un modelo con
     * columna `team_id`.
     */
    protected function assertBelongsToTenant(Team|int $tenant, mixed $result): void
    {
        $teamId = $tenant instanceof Team ? $tenant->id : $tenant;

        foreach ($this->modelsIn($result) as $model) {
            if (! array_key_exists('team_id', $model->getAttributes())) {
                continue;
            }

            $this->assertSame(
                $teamId,
                (int) $model->team_id,
                'Fuga cross-tenant: el resultado incluye un '.$model::class.
                " (id {$model->getKey()}) del team {$model->team_id}, y se pidió el team {$teamId}.\n".
                "Regla: CLAUDE.md §2.1.\n",
            );
        }
    }

    /**
     * Huella de todas las filas que pertenecen a OTROS tenants, por tabla.
     *
     * @return array<string, list<string>>
     */
    private function foreignTenantRows(int $teamId): array
    {
        $snapshot = [];

        foreach (Schema::getTables() as $table) {
            $name = $table['name'];

            if (! Schema::hasColumn($name, 'team_id')) {
                continue;
            }

            $rows = DB::table($name)
                ->whereNotNull('team_id')
                ->where('team_id', '!=', $teamId)
                ->get()
                ->map(fn ($row) => json_encode((array) $row))
                ->all();

            sort($rows);

            $snapshot[$name] = $rows;
        }

        return $snapshot;
    }

    /**
     * @return iterable<Model>
     */
    private function modelsIn(mixed $result): iterable
    {
        if ($result instanceof Model) {
            yield $result;

            return;
        }

        if ($result instanceof EloquentCollection || $result instanceof Collection || is_array($result)) {
            foreach ($result as $item) {
                yield from $this->modelsIn($item);
            }
        }
    }
}
