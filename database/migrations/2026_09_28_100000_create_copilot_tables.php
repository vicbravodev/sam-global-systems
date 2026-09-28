<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SAM Copilot: conversational assistant over the tenant's fleet data.
     * Each conversation belongs to one user inside one tenant; each message
     * keeps the structured cards it rendered plus its own usage metering
     * (tokens, cost, latency) so the usage dashboard never has to re-derive
     * it from the billing ledger.
     */
    public function up(): void
    {
        Schema::create('copilot_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->boolean('is_pinned')->default(false);
            $table->unsignedInteger('messages_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index('team_id');
            $table->index(['team_id', 'user_id', 'last_message_at']);
        });

        Schema::create('copilot_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('copilot_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role');
            $table->text('content');
            $table->string('intent')->nullable();
            $table->string('channel')->nullable();
            $table->json('context_json')->nullable();
            $table->json('blocks_json')->nullable();
            $table->json('tools_json')->nullable();
            $table->json('sources_json')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_estimate', 12, 6)->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->smallInteger('feedback')->nullable();
            $table->timestamps();

            $table->index('team_id');
            $table->index(['team_id', 'role', 'created_at']);
            $table->index(['team_id', 'user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('copilot_messages');
        Schema::dropIfExists('copilot_conversations');
    }
};
