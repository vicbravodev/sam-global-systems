<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * El create original encadenó `->unique()` DESPUÉS de `constrained()`, que
 * devuelve la definición de la foreign key, no la de la columna: el unique
 * nunca se creó y `tenant_brandings` admitía varias filas por tenant (el
 * branding es 1:1 con el team).
 *
 * Additive-only: si ya hay duplicados no se tocan datos (nada de DELETE en
 * migraciones) — se deja un warning y el índice sin crear hasta que un
 * humano deduplique y vuelva a correr esta migración.
 */
return new class extends Migration
{
    private const INDEX = 'tenant_brandings_team_id_unique';

    public function up(): void
    {
        if (Schema::hasIndex('tenant_brandings', self::INDEX)) {
            return;
        }

        $duplicated = DB::table('tenant_brandings')
            ->select('team_id')
            ->groupBy('team_id')
            ->havingRaw('count(*) > 1')
            ->pluck('team_id');

        if ($duplicated->isNotEmpty()) {
            Log::warning('tenant_brandings tiene filas duplicadas por team; no se crea el índice único.', [
                'team_ids' => $duplicated->all(),
            ]);

            return;
        }

        Schema::table('tenant_brandings', function (Blueprint $table) {
            $table->unique('team_id', self::INDEX);
        });
    }

    public function down(): void
    {
        if (Schema::hasIndex('tenant_brandings', self::INDEX)) {
            Schema::table('tenant_brandings', function (Blueprint $table) {
                $table->dropUnique(self::INDEX);
            });
        }
    }
};
