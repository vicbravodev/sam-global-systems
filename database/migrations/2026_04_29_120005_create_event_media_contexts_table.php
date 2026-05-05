<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_media_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('normalized_event_id')->constrained('normalized_events')->cascadeOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('integration_providers')->nullOnDelete();
            $table->foreignId('file_object_id')->nullable()->constrained('file_objects')->nullOnDelete();
            $table->foreignId('source_attachment_id')->nullable()->constrained('raw_event_attachments')->nullOnDelete();
            $table->string('media_type');
            $table->string('media_role');
            $table->string('media_url')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->string('storage_path')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('mime_type')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('window_start')->nullable();
            $table->timestamp('window_end')->nullable();
            $table->string('availability_status')->default('not_available');
            $table->string('retrieval_status')->default('not_requested');
            $table->string('checksum')->nullable();
            $table->jsonb('metadata_json')->nullable();
            $table->timestamps();

            $table->index('team_id');
            $table->index('normalized_event_id');
            $table->unique(['normalized_event_id', 'storage_path'], 'event_media_event_storage_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_media_contexts');
    }
};
