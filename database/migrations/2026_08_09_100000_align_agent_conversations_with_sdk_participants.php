<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Migrations\AiMigration;

/**
 * laravel/ai v0.10 replaced the `user_id` owner column on the SDK conversation
 * tables with a polymorphic `participant_type` / `participant_id` pair, and added
 * `approval_state` to messages for human-in-the-loop approvals.
 *
 * This project publishes its own copy of those tables
 * (`2026_05_08_015328_create_agent_conversations_table`), so the schema does not
 * follow the package automatically and would break the moment an agent opts into
 * the `Conversational` middleware. This migration realigns it additively:
 * the new columns are created, `user_id` is left in place (§8.4 forbids
 * destructive migrations) and simply stops being written to.
 */
return new class extends AiMigration
{
    public function up(): void
    {
        if (Schema::hasTable('agent_conversations')) {
            Schema::table('agent_conversations', function (Blueprint $table) {
                if (! Schema::hasColumn('agent_conversations', 'participant_type')) {
                    $table->string('participant_type')->nullable();
                }

                if (! Schema::hasColumn('agent_conversations', 'participant_id')) {
                    $table->unsignedBigInteger('participant_id')->nullable();
                }
            });

            Schema::table('agent_conversations', function (Blueprint $table) {
                $table->index(
                    ['participant_type', 'participant_id', 'updated_at'],
                    'participant_updated_at_index',
                );
            });
        }

        if (Schema::hasTable('agent_conversation_messages')) {
            Schema::table('agent_conversation_messages', function (Blueprint $table) {
                if (! Schema::hasColumn('agent_conversation_messages', 'participant_type')) {
                    $table->string('participant_type')->nullable();
                }

                if (! Schema::hasColumn('agent_conversation_messages', 'participant_id')) {
                    $table->unsignedBigInteger('participant_id')->nullable();
                }

                if (! Schema::hasColumn('agent_conversation_messages', 'approval_state')) {
                    $table->text('approval_state')->nullable();
                }
            });

            Schema::table('agent_conversation_messages', function (Blueprint $table) {
                $table->index(['participant_type', 'participant_id'], 'participant_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('agent_conversations')) {
            Schema::table('agent_conversations', function (Blueprint $table) {
                $table->dropIndex('participant_updated_at_index');
                $table->dropColumn(['participant_type', 'participant_id']);
            });
        }

        if (Schema::hasTable('agent_conversation_messages')) {
            Schema::table('agent_conversation_messages', function (Blueprint $table) {
                $table->dropIndex('participant_index');
                $table->dropColumn(['participant_type', 'participant_id', 'approval_state']);
            });
        }
    }
};
