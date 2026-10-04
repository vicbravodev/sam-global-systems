<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Monitoreo HOS PR 2: las infracciones que el PR 1 dejó abiertas (sólo
     * observaba) ocurrieron antes del despliegue. Con `ladder_step = 1` el
     * planificador las da por levantadas (`violation_raised`): el primer
     * sondeo no le avisa al chofer ni levanta un incidente viejo.
     *
     * Corre sobre todos los tenants a propósito (DB::table, sin scope de
     * tenant): sólo toca filas de episodios, nunca las mezcla. Idempotente:
     * sólo mueve las que siguen en el escalón 0, así que repetirla nunca
     * regresa un episodio que ya insiste con el PR 2.
     */
    public function up(): void
    {
        DB::table('hos_episodes')
            ->where('situation', 'violation')
            ->whereNull('resolved_at')
            ->where('ladder_step', 0)
            ->update(['ladder_step' => 1]);
    }

    /**
     * Sin vuelta atrás: no se sabe qué filas estaban en 0 antes de `up()`, y
     * regresarlas haría que el PR 2 avisara y levantara incidentes viejos.
     */
    public function down(): void
    {
        //
    }
};
