<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Alta automática del webhook de Samsara (spec 2026-10-04, alertas 05):
     * SAM crea el webhook y su propia alerta de pánico con el token del
     * cliente, guarda la Secret Key que Samsara devuelve y los ids para
     * limpiarlos al desconectar o rotar. `manual` es el flujo de siempre (el
     * cliente pega la Secret Key). `previous_secret` vale sólo durante la
     * gracia de una rotación.
     */
    public function up(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table) {
            $table->string('setup_mode', 16)->default('manual')->after('secret_configured_at');
            $table->string('setup_status', 32)->nullable()->after('setup_mode');
            $table->string('setup_error', 255)->nullable()->after('setup_status');
            $table->string('provider_webhook_id', 64)->nullable()->after('setup_error');
            $table->string('provider_alert_configuration_id', 64)->nullable()->after('provider_webhook_id');
            $table->timestamp('provisioned_at')->nullable()->after('provider_alert_configuration_id');
            $table->text('previous_secret')->nullable()->after('provisioned_at');
            $table->timestamp('previous_secret_expires_at')->nullable()->after('previous_secret');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table) {
            $table->dropColumn([
                'setup_mode',
                'setup_status',
                'setup_error',
                'provider_webhook_id',
                'provider_alert_configuration_id',
                'provisioned_at',
                'previous_secret',
                'previous_secret_expires_at',
            ]);
        });
    }
};
