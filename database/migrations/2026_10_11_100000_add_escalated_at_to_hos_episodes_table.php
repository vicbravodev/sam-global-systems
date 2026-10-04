<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Monitoreo HOS PR 2: cuándo la escalera de un episodio llegó al
     * incidente. El episodio sigue abierto tras escalar (si se cerrara, el
     * detector abriría otro igual en el siguiente sondeo); esta marca detiene
     * la escalera y le dice a la corrección que hay un incidente que atender.
     */
    public function up(): void
    {
        Schema::table('hos_episodes', function (Blueprint $table) {
            $table->timestamp('escalated_at')->nullable()->after('next_nudge_at');
        });
    }

    public function down(): void
    {
        Schema::table('hos_episodes', function (Blueprint $table) {
            $table->dropColumn('escalated_at');
        });
    }
};
