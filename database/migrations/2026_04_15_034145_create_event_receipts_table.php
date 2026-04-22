<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raw_event_id')->constrained('raw_events')->cascadeOnDelete();
            $table->string('received_via');
            $table->string('request_id')->nullable();
            $table->string('source_ip')->nullable();
            $table->string('user_agent')->nullable();
            $table->unsignedSmallInteger('http_status_returned')->nullable();
            $table->boolean('signature_valid')->nullable();
            $table->timestamp('received_at');
            $table->jsonb('metadata_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_receipts');
    }
};
