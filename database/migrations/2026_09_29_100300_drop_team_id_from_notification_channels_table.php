<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Los canales de notificación son de plataforma: SAM opera la mensajería
     * con credenciales propias (env TWILIO_*) y cada tenant sólo decide, vía
     * `tenant_channel_toggles`, qué canales apaga para su equipo. El enfoque
     * anterior de canales/credenciales por tenant desaparece por completo.
     *
     * El producto no está en producción y no hay canales de tenant que
     * preservar; los que existieran en entornos de desarrollo se eliminan
     * antes de quitar la columna para no mezclarlos con los de plataforma.
     */
    public function up(): void
    {
        DB::table('notification_channels')->whereNotNull('team_id')->delete();

        Schema::table('notification_channels', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'code']);
            $table->dropIndex(['team_id', 'channel_type']);
            $table->dropIndex(['team_id']);
            $table->dropForeign(['team_id']);
        });

        Schema::table('notification_channels', function (Blueprint $table) {
            $table->dropColumn('team_id');
            $table->unique('code');
            $table->index('channel_type');
        });
    }

    public function down(): void
    {
        Schema::table('notification_channels', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropIndex(['channel_type']);
        });

        Schema::table('notification_channels', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->index('team_id');
            $table->index(['team_id', 'channel_type']);
            $table->unique(['team_id', 'code']);
        });
    }
};
