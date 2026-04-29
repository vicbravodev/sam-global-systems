<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role')->nullable();
            $table->string('notification_type');
            $table->jsonb('allowed_channels_json');
            $table->boolean('muted')->default(false);
            $table->jsonb('quiet_hours_json')->nullable();
            $table->jsonb('escalation_fallback_json')->nullable();
            $table->timestamps();

            $table->index('team_id');
            $table->index(['team_id', 'user_id', 'notification_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
