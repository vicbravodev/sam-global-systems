<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enganche tracto–remolque (decisión 2026-10-05). Samsara no dice qué
     * tracto lleva qué caja (las asignaciones chofer–remolque vienen vacías),
     * así que SAM lo infiere por co-movimiento. Cada fila es un tramo: abre al
     * enganchar y se cierra (`decoupled_at`) al soltarse o cambiar de tracto.
     * El historial queda para saber qué caja llevaba un tracto en el momento
     * de un incidente. Un tracto lleva varios remolques (full: caja, dolly,
     * caja); un remolque, un solo tracto a la vez (índice parcial).
     */
    public function up(): void
    {
        Schema::create('asset_couplings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tractor_asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('trailer_asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('source', 32);
            $table->timestamp('coupled_at');
            $table->timestamp('last_confirmed_at');
            $table->timestamp('decoupled_at')->nullable();
            $table->string('decouple_reason', 32)->nullable();
            // Términos de la última evaluación que lo confirmó (puntos
            // comparados, coincidentes, distancia media): rehacer a mano.
            $table->json('evidence_json')->nullable();
            $table->timestamps();

            $table->index('team_id');
            $table->index(['team_id', 'tractor_asset_id', 'decoupled_at']);
            $table->index(['trailer_asset_id', 'coupled_at']);
        });

        // PostgreSQL y SQLite aceptan índices parciales con la misma sintaxis.
        DB::statement('CREATE UNIQUE INDEX asset_couplings_one_open_per_trailer ON asset_couplings (trailer_asset_id) WHERE decoupled_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_couplings');
    }
};
