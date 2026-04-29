<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('provider');
            $table->string('channel_type');
            $table->jsonb('config_json')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('supports_priority')->default(false);
            $table->boolean('supports_template')->default(true);
            $table->timestamps();

            $table->index('team_id');
            $table->index(['team_id', 'channel_type']);
            $table->unique(['team_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_channels');
    }
};
