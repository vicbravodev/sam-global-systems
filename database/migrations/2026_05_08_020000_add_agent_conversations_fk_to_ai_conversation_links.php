<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Adds the FK to the Laravel AI SDK's `agent_conversations` table once
        // the SDK migration has run. Skipping in environments where the SDK
        // has not been published (e.g. exotic CI matrices) keeps the upgrade
        // path safe.
        if (! Schema::hasTable('agent_conversations')) {
            return;
        }

        Schema::table('ai_conversation_links', function (Blueprint $table) {
            $table->foreign('agent_conversation_id')
                ->references('id')
                ->on('agent_conversations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('agent_conversations')) {
            return;
        }

        Schema::table('ai_conversation_links', function (Blueprint $table) {
            $table->dropForeign(['agent_conversation_id']);
        });
    }
};
