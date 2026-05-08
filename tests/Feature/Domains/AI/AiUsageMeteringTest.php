<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Models\AIConversationLink;
use App\Domains\Tenancy\Events\UsageRecorded;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Models\User;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Mockery;
use Tests\TestCase;

class AiUsageMeteringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AIMeterSeeder::class);
    }

    public function test_usage_listener_records_tokens_via_conversation_link(): void
    {
        Event::fake([UsageRecorded::class]);

        $user = User::factory()->create();
        $team = $user->currentTeam;

        $link = AIConversationLink::factory()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'purpose' => 'event_evaluation',
        ]);

        $event = $this->fakeAgentPrompted(
            invocationId: $link->agent_conversation_id,
            conversationId: $link->agent_conversation_id,
            promptTokens: 250,
            completionTokens: 80,
        );

        event($event);

        Event::assertDispatched(UsageRecorded::class, fn (UsageRecorded $e) => $e->meterCode === 'ai_tokens_in'
            && $e->teamId === $team->id
            && str_starts_with($e->eventKey, 'ai_tokens_in:sdk:'));

        Event::assertDispatched(UsageRecorded::class, fn (UsageRecorded $e) => $e->meterCode === 'ai_tokens_out'
            && $e->teamId === $team->id
            && str_starts_with($e->eventKey, 'ai_tokens_out:sdk:'));
    }

    public function test_usage_listener_no_ops_when_no_link_registered(): void
    {
        Event::fake([UsageRecorded::class]);

        event($this->fakeAgentPrompted(
            invocationId: 'orphaned-invocation-uuid',
            conversationId: null,
            promptTokens: 200,
            completionTokens: 60,
        ));

        Event::assertNotDispatched(UsageRecorded::class);
    }

    public function test_usage_listener_handles_streamed_event(): void
    {
        Event::fake([UsageRecorded::class]);

        $user = User::factory()->create();
        $team = $user->currentTeam;

        $link = AIConversationLink::factory()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
        ]);

        $invocationId = $link->agent_conversation_id;
        $prompt = Mockery::mock(AgentPrompt::class);
        $response = new AgentResponse($invocationId, 'streamed text', new Usage(promptTokens: 100, completionTokens: 25), new Meta('openai', 'gpt-test'));
        $response->withinConversation($link->agent_conversation_id, (object) ['id' => $user->id]);

        event(new AgentStreamed($invocationId, $prompt, $response));

        Event::assertDispatched(UsageRecorded::class, fn (UsageRecorded $e) => $e->meterCode === 'ai_tokens_in'
            && $e->teamId === $team->id);
    }

    public function test_usage_listener_idempotent_on_duplicate_event(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $link = AIConversationLink::factory()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
        ]);

        $event = $this->fakeAgentPrompted(
            invocationId: $link->agent_conversation_id,
            conversationId: $link->agent_conversation_id,
            promptTokens: 100,
            completionTokens: 50,
        );

        event($event);
        event($event);

        $this->assertSame(2, UsageEvent::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->whereIn('event_key', [
                'ai_tokens_in:sdk:'.$link->agent_conversation_id,
                'ai_tokens_out:sdk:'.$link->agent_conversation_id,
            ])
            ->count());
    }

    private function fakeAgentPrompted(
        string $invocationId,
        ?string $conversationId,
        int $promptTokens,
        int $completionTokens,
    ): AgentPrompted {
        $usage = new Usage(promptTokens: $promptTokens, completionTokens: $completionTokens);
        $meta = new Meta('openai', 'gpt-test');

        $response = new AgentResponse($invocationId, 'fake text', $usage, $meta);

        if ($conversationId !== null) {
            $response->withinConversation($conversationId, (object) ['id' => 0]);
        }

        return new AgentPrompted($invocationId, Mockery::mock(AgentPrompt::class), $response);
    }
}
