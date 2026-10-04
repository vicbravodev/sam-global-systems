<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Monitoreo HOS (spec 2026-10-04): último snapshot de relojes HOS por
     * chofer vigilado. Es el "antes" contra el que se detectan transiciones
     * (p. ej. fin de pausa) en el siguiente sondeo.
     */
    public function up(): void
    {
        Schema::create('hos_driver_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('duty_status', 32)->nullable();
            $table->timestamp('status_since')->nullable();
            $table->unsignedInteger('break_remaining_s')->nullable();
            $table->unsignedInteger('drive_remaining_s')->nullable();
            $table->unsignedInteger('shift_remaining_s')->nullable();
            $table->unsignedInteger('cycle_remaining_s')->nullable();
            $table->unsignedInteger('violation_s')->default(0);
            $table->timestamp('app_disconnected_since')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->index('team_id');
            $table->unique(['team_id', 'driver_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hos_driver_states');
    }
};
