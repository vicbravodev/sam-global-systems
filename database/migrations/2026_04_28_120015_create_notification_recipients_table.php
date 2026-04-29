<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('recipient_type');
            $table->string('recipient_reference_id')->nullable();
            $table->string('name')->nullable();
            $table->string('address');
            $table->string('channel_preference')->nullable();
            $table->string('role')->nullable();
            $table->jsonb('metadata_json')->nullable();
            $table->timestamps();

            $table->index('team_id');
            $table->index('notification_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_recipients');
    }
};
