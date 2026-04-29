<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('notification_recipients')->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('notification_channels')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('provider_message_id')->nullable();
            $table->string('status');
            $table->unsignedTinyInteger('attempt_number')->default(1);
            $table->jsonb('payload_json')->nullable();
            $table->jsonb('response_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index('team_id');
            $table->index(['notification_id', 'status']);
            $table->unique(['notification_id', 'recipient_id', 'channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
