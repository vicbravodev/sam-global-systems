<?php

namespace Tests\Feature\Domains\AI;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Migrations\AiMigration;
use Tests\TestCase;

/**
 * This project publishes its own copy of the laravel/ai conversation tables, so
 * they do not follow the package schema automatically. These assertions fail the
 * moment the published copy drifts from what the installed SDK writes — which is
 * exactly what happened when v0.10 swapped `user_id` for a polymorphic participant.
 */
class AgentConversationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_conversations_carries_the_polymorphic_participant_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumn('agent_conversations', 'participant_type'),
            'laravel/ai >= 0.10 writes conversation owners as participant_type; the published table must expose it',
        );

        $this->assertTrue(
            Schema::hasColumn('agent_conversations', 'participant_id'),
            'laravel/ai >= 0.10 writes conversation owners as participant_id; the published table must expose it',
        );
    }

    public function test_agent_conversation_messages_carries_participant_and_step_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumn('agent_conversation_messages', 'participant_type'),
            'Message rows written by the SDK carry participant_type since v0.10',
        );

        $this->assertTrue(
            Schema::hasColumn('agent_conversation_messages', 'participant_id'),
            'Message rows written by the SDK carry participant_id since v0.10',
        );

        $this->assertTrue(
            Schema::hasColumn('agent_conversation_messages', 'steps'),
            'laravel/ai 1.0 stores each assistant turn as steps',
        );

        $this->assertTrue(
            Schema::hasColumn('agent_conversation_messages', 'status'),
            'laravel/ai 1.0 replaced approval_state with a message status',
        );

        foreach (['tool_calls', 'tool_results', 'approval_state'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('agent_conversation_messages', $column),
                "laravel/ai 1.0 no longer writes {$column}; a leftover NOT NULL column would break inserts",
            );
        }
    }

    public function test_steps_migration_runs_on_the_ai_conversations_connection(): void
    {
        $path = database_path('migrations/2026_10_05_100000_store_agent_conversation_messages_as_steps.php');
        $migration = require $path;

        $this->assertInstanceOf(AiMigration::class, $migration, 'Like the package migrations, it must honour ai.conversations.connection');

        config(['ai.conversations.connection' => 'ai_conversations']);
        $this->assertSame('ai_conversations', $migration->getConnection());

        // Every schema and data statement goes through that connection, never the default one.
        $this->assertDoesNotMatchRegularExpression('/\b(Schema|DB)::(?!connection\()/', (string) file_get_contents($path));
    }
}
