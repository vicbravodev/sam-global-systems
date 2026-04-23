<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geofence_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('normalized_event_id')->constrained('normalized_events')->cascadeOnDelete();
            $table->foreignId('geofence_id')->constrained('geofences')->cascadeOnDelete();
            $table->string('match_type');
            $table->timestamp('matched_at');
            $table->unsignedInteger('distance_meters')->nullable();
            $table->jsonb('metadata_json')->nullable();
            $table->timestamps();

            $table->index('normalized_event_id');
            $table->index('geofence_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geofence_matches');
    }
};
