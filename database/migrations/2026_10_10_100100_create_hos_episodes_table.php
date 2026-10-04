<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Monitoreo HOS (spec 2026-10-04): cada situación detectada (break,
     * manejo, turno, ciclo, fin de pausa, violación) es un episodio con su
     * ciclo de vida. `ladder_step`/`next_nudge_at`/`incident_id` los usa la
     * escalera de insistencia (PR 2). El índice parcial garantiza un solo
     * episodio ABIERTO por chofer y situación: es la defensa de idempotencia
     * ante sondeos solapados.
     */
    public function up(): void
    {
        Schema::create('hos_episodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('situation', 32);
            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 32)->nullable();
            $table->unsignedSmallInteger('ladder_step')->default(0);
            $table->timestamp('next_nudge_at')->nullable();
            $table->foreignId('incident_id')->nullable()->constrained()->nullOnDelete();
            $table->json('snapshot_json');
            $table->timestamps();

            $table->index('team_id');
            $table->index(['team_id', 'resolved_at', 'next_nudge_at']);
        });

        // PostgreSQL y SQLite aceptan índices parciales con la misma sintaxis.
        DB::statement('CREATE UNIQUE INDEX hos_episodes_one_open_per_situation ON hos_episodes (team_id, driver_id, situation) WHERE resolved_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('hos_episodes');
    }
};
