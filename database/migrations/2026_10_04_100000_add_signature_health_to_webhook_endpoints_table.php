<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El secret de un webhook de Samsara lo genera Samsara: hasta que el tenant lo
 * copia a SAM el endpoint queda sin secret (`null` = pendiente de configurar)
 * y todo lo que llegue se rechaza. La salud se mide por separado: último
 * webhook con firma válida y último rechazado (con su razón).
 *
 * Tabla sin `team_id` propio: el tenant sale de `tenant_integrations`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table) {
            $table->text('secret')->nullable()->change();
            $table->timestamp('secret_configured_at')->nullable();
            $table->timestamp('last_valid_received_at')->nullable();
            $table->timestamp('last_rejected_at')->nullable();
            $table->string('last_rejection_reason', 64)->nullable();
        });
    }

    /**
     * `secret` se queda nullable: volverlo NOT NULL fallaría con los
     * endpoints que siguen pendientes de configurar.
     */
    public function down(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table) {
            $table->dropColumn(['secret_configured_at', 'last_valid_received_at', 'last_rejected_at', 'last_rejection_reason']);
        });
    }
};
