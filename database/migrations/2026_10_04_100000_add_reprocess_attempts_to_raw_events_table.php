<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contador de re-despachos del barrido de atascados (ReprocessStuckRawEventsJob).
 * Distinto de `processing_attempts`, que cuenta cada intento de los jobs
 * (incluidos sus reintentos): éste sólo cuenta rescates, y es el que pone el
 * tope para no ciclar un evento envenenado para siempre. Aditivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_events', function (Blueprint $table) {
            $table->unsignedSmallInteger('reprocess_attempts')->default(0);
            $table->timestamp('last_reprocessed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('raw_events', function (Blueprint $table) {
            $table->dropColumn(['reprocess_attempts', 'last_reprocessed_at']);
        });
    }
};
