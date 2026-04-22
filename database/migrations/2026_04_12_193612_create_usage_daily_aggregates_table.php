<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_daily_aggregates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('usage_meter_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedBigInteger('quantity_sum')->default(0);
            $table->unsignedBigInteger('quantity_max')->default(0);
            $table->timestamps();

            $table->unique(['team_id', 'usage_meter_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_daily_aggregates');
    }
};
