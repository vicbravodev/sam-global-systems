<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_deduplication_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('event_source_id')->constrained('event_sources')->cascadeOnDelete();
            $table->string('deduplication_key');
            $table->foreignId('raw_event_id')->constrained('raw_events')->cascadeOnDelete();
            $table->timestamp('first_seen_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['event_source_id', 'deduplication_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_deduplication_keys');
    }
};
