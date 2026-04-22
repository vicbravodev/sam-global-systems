<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('usage_meter_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('included_quantity')->default(0);
            $table->decimal('overage_unit_price', 10, 4)->default(0);
            $table->string('billing_model');
            $table->jsonb('tiers_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_rates');
    }
};
