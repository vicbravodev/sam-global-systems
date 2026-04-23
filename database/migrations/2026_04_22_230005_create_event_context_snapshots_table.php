<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_context_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('normalized_event_id')->unique()->constrained('normalized_events')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->timestamp('event_occurred_at');
            $table->unsignedTinyInteger('context_version')->default(1);
            $table->jsonb('location_snapshot_json')->nullable();
            $table->jsonb('asset_snapshot_json')->nullable();
            $table->jsonb('driver_snapshot_json')->nullable();
            $table->jsonb('telemetry_snapshot_json')->nullable();
            $table->jsonb('geofence_snapshot_json')->nullable();
            $table->jsonb('incidents_snapshot_json')->nullable();
            $table->jsonb('recent_history_snapshot_json')->nullable();
            $table->jsonb('media_snapshot_json')->nullable();
            $table->jsonb('signals_json')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'event_occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_context_snapshots');
    }
};
