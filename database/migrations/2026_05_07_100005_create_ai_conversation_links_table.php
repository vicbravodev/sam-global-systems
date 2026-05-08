<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversation_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // SPEC-09-SDK-DEFERRED: FK to `agent_conversations.id` is added in
            // PR #2b once `laravel/ai` SDK ships its own migrations. Until then
            // the column stays unconstrained so `EvaluateEventWithAI` and
            // multimodal flows can persist a stable correlation id.
            $table->unsignedBigInteger('agent_conversation_id');
            $table->foreignId('normalized_event_id')->nullable()->constrained('normalized_events')->nullOnDelete();
            $table->foreignId('evaluation_id')->nullable()->constrained('ai_event_evaluations')->nullOnDelete();
            $table->string('purpose')->nullable();
            $table->jsonb('metadata_json')->nullable();
            $table->timestamps();

            $table->index('team_id');
            $table->index('user_id');
            $table->index('agent_conversation_id');
            $table->unique(['team_id', 'agent_conversation_id'], 'ai_conv_links_team_conv_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_conversation_links');
    }
};
