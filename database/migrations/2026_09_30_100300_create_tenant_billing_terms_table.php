<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Términos comerciales por tenant (decisión 2026-09-28: sin planes, cada
     * cliente tiene su precio y su tope). Toda columna nullable significa
     * "usar el default de plataforma de config/billing.php".
     */
    public function up(): void
    {
        Schema::create('tenant_billing_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->unsignedInteger('included_assets')->nullable();
            $table->unsignedInteger('min_billable_assets')->nullable();
            $table->unsignedInteger('ai_fair_use_per_asset')->nullable();
            $table->decimal('ai_overage_unit_price', 10, 4)->nullable();
            $table->decimal('messaging_markup_percent', 6, 2)->nullable();
            $table->decimal('fx_usd_rate', 10, 4)->nullable();
            $table->jsonb('volume_tiers_json')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_billing_terms');
    }
};
