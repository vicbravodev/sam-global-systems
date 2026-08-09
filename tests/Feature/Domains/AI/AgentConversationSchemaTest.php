<?php

namespace Tests\Feature\Domains\AI;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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

    public function test_agent_conversation_messages_carries_participant_and_approval_columns(): void
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
            Schema::hasColumn('agent_conversation_messages', 'approval_state'),
            'v0.10 added approval_state to messages for human-in-the-loop approvals',
        );
    }
}
