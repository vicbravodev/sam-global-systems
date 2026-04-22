<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_risk_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->unique()->constrained('drivers')->cascadeOnDelete();
            $table->decimal('risk_score', 5, 2)->nullable();
            $table->string('risk_level')->nullable();
            $table->unsignedInteger('incidents_count')->default(0);
            $table->unsignedInteger('harsh_events_count')->default(0);
            $table->unsignedInteger('fatigue_flags_count')->default(0);
            $table->timestamp('last_calculated_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_risk_profiles');
    }
};
