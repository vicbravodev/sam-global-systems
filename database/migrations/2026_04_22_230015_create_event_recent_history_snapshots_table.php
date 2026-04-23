<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_recent_history_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('normalized_event_id')->unique()->constrained('normalized_events')->cascadeOnDelete();
            $table->timestamp('window_start');
            $table->timestamp('window_end');
            $table->unsignedInteger('recent_events_count')->default(0);
            $table->unsignedInteger('recent_incidents_count')->default(0);
            $table->unsignedInteger('recent_same_type_count')->default(0);
            $table->unsignedInteger('recent_high_severity_count')->default(0);
            $table->jsonb('recent_locations_json')->nullable();
            $table->jsonb('recent_flags_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_recent_history_snapshots');
    }
};
