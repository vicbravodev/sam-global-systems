<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('source_type');
            $table->string('source_reference_id')->nullable();
            $table->string('notification_type');
            $table->string('priority');
            $table->string('status');
            $table->string('subject')->nullable();
            $table->text('body_preview')->nullable();
            $table->foreignId('template_id')->nullable()->constrained('notification_templates')->nullOnDelete();
            $table->string('triggered_by_type');
            $table->unsignedBigInteger('triggered_by_id')->nullable();
            $table->string('event_key')->nullable();
            $table->jsonb('payload_json')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('team_id');
            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'notification_type']);
            $table->index(['source_type', 'source_reference_id']);
            $table->unique(['team_id', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
