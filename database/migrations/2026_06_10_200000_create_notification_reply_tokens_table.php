<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_reply_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->foreignId('notification_id')->nullable()->constrained('notifications')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel_type', 32);
            $table->string('address');
            $table->string('token', 16)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('consumed_action', 32)->nullable();
            $table->jsonb('reply_payload_json')->nullable();
            $table->timestamps();

            $table->index('team_id');
            $table->index(['incident_id', 'address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_reply_tokens');
    }
};
