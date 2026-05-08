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
            // Nullable because evaluations triggered by background jobs do not
            // run in a user request context. When a user does drive an
            // evaluation (e.g. operator console), we capture the link here.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Mirrors `agent_conversations.id` (UUID/CHAR(36)) from the
            // Laravel AI SDK. The FK constraint is added in the follow-up
            // migration after the SDK's own table is created.
            $table->string('agent_conversation_id', 36);
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
