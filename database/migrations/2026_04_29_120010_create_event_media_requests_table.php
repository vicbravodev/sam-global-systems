<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_media_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('normalized_event_id')->constrained('normalized_events')->cascadeOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('integration_providers')->nullOnDelete();
            $table->string('request_type');
            $table->timestamp('requested_at');
            $table->string('status')->default('pending');
            $table->jsonb('response_metadata_json')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('team_id');
            $table->index(['normalized_event_id', 'status']);
            $table->index(['normalized_event_id', 'request_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_media_requests');
    }
};
