<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_call_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('notification_channel_id')->nullable()->constrained('notification_channels')->nullOnDelete();
            $table->string('phone', 32);
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->string('status', 16)->default('pending');
            $table->string('outcome', 24)->nullable();
            $table->string('digits_received', 8)->nullable();
            $table->string('call_sid', 64)->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index('team_id');
            $table->unique(['incident_id', 'attempt']);
            $table->index('call_sid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_call_verifications');
    }
};
