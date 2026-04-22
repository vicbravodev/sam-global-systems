<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('event_source_id')->constrained('event_sources')->cascadeOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('integration_providers')->nullOnDelete();
            $table->string('external_event_id')->nullable();
            $table->string('event_type_raw')->nullable();
            $table->jsonb('payload_json');
            $table->jsonb('headers_json')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('occurred_at')->nullable();
            $table->string('deduplication_key')->nullable();
            $table->string('status')->default('received');
            $table->string('checksum')->nullable();
            $table->unsignedTinyInteger('processing_attempts')->default(0);
            $table->timestamp('last_processing_attempt_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'received_at']);
            $table->index(['team_id', 'status']);
            $table->index('deduplication_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_events');
    }
};
