<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Models\AIConversationLink;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Models\User;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Mockery;
use Tests\TestCase;

/**
 * Los tokens de IA los cobra quien llama al SDK, con su propia event_key:
 * la evaluación (`ai_tokens_in:{evaluation_id}`) y el Copilot
 * (RecordCopilotUsage). Un listener genérico sobre los eventos del SDK
 * cobraría por segunda vez la misma llamada.
 */
class AiUsageMeteringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AIMeterSeeder::class);
    }

    public function test_sdk_prompted_event_never_meters_tokens_on_its_own(): void
    {
        $user = User::factory()->create();

        // Aun con el link de conversación que deja la evaluación.
        $link = AIConversationLink::factory()->create([
            'team_id' => $user->currentTeam->id,
            'purpose' => 'event_evaluation',
        ]);

        event(new AgentPrompted($link->agent_conversation_id, Mockery::mock(AgentPrompt::class), $this->response($link->agent_conversation_id)));

        $this->assertSame(0, UsageEvent::withoutGlobalScopes()->count());
    }

    public function test_sdk_streamed_event_never_meters_tokens_on_its_own(): void
    {
        $user = User::factory()->create();

        $link = AIConversationLink::factory()->create([
            'team_id' => $user->currentTeam->id,
            'purpose' => 'event_evaluation',
        ]);

        event(new AgentStreamed($link->agent_conversation_id, Mockery::mock(AgentPrompt::class), $this->response($link->agent_conversation_id)));

        $this->assertSame(0, UsageEvent::withoutGlobalScopes()->count());
    }

    private function response(string $invocationId): AgentResponse
    {
        return new AgentResponse($invocationId, 'fake text', new TextUsage(inputTokens: 250, outputTokens: 80), new Meta('openai', 'gpt-test'));
    }
}
