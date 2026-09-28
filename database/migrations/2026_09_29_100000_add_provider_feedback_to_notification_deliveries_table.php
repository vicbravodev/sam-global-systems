<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Feedback real del proveedor por entrega. Hasta ahora una entrega Twilio
     * quedaba "delivered" en cuanto la API aceptaba el envío (status `queued`),
     * sin saber si llegó, si el operador la rechazó o si contestaron la
     * llamada. Estas columnas reciben los status callbacks y el reconciliador.
     *
     * `permanent_failure` marca los fallos que no tiene sentido reintentar
     * (número inválido, opt-out, WhatsApp fuera de ventana, canal sin
     * configurar): la entrega salta directo al canal de fallback.
     */
    public function up(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->string('provider_status')->nullable();
            $table->string('provider_error_code')->nullable();
            $table->boolean('permanent_failure')->default(false);
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->unsignedInteger('call_duration_seconds')->nullable();
            $table->unsignedSmallInteger('segments')->nullable();
            $table->timestamp('last_provider_event_at')->nullable();

            $table->index('provider_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropIndex(['provider_message_id']);
            $table->dropColumn([
                'provider_status',
                'provider_error_code',
                'permanent_failure',
                'accepted_at',
                'read_at',
                'answered_at',
                'call_duration_seconds',
                'segments',
                'last_provider_event_at',
            ]);
        });
    }
};
