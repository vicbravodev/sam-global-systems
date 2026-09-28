<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Margen sobre el costo real del proveedor para las tarifas `cost_plus`
     * (mensajería Twilio): línea de factura = costo × (1 + markup / 100).
     */
    public function up(): void
    {
        Schema::table('billing_rates', function (Blueprint $table) {
            $table->decimal('markup_percent', 6, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('billing_rates', function (Blueprint $table) {
            $table->dropColumn('markup_percent');
        });
    }
};
