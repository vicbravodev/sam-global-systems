<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Models\AIConversationLink;
use App\Domains\Tenancy\Events\UsageRecorded;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\User;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Mockery;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class AiUsageMeteringTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

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

        $this->assertCount(2, $this->systemLogEntries('billing.usage.recorded'));
        $this->assertSystemLogged('billing.usage.recorded', fn (array $c) => $c['input']['meter_code'] === 'ai_tokens_in'
            && $c['input']['team_id'] === $team->id
            && $c['input']['event_key'] === 'ai_tokens_in:sdk:'.$link->agent_conversation_id
            && $c['calc']['quantity'] === 250);
        $this->assertSystemLogged('billing.usage.recorded', fn (array $c) => $c['input']['meter_code'] === 'ai_tokens_out'
            && $c['input']['team_id'] === $team->id
            && $c['calc']['quantity'] === 80);
        $this->assertSystemNotLogged('ai.usage.not_metered');
        $this->assertNoSensitiveDataLogged();
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

        $this->assertSystemLogged('ai.usage.not_metered', fn (array $c) => $c['reason'] === 'no_conversation_link'
            && $c['input']['invocation_id'] === 'orphaned-invocation-uuid'
            && $c['calc']['conversation_id_present'] === false);
        $this->assertSame('debug', $this->systemLogEntries('ai.usage.not_metered')[0]['level']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_usage_listener_says_when_a_direction_has_zero_tokens(): void
    {
        Event::fake([UsageRecorded::class]);

        $user = User::factory()->create();
        $link = AIConversationLink::factory()->create([
            'team_id' => $user->currentTeam->id,
            'user_id' => $user->id,
        ]);

        event($this->fakeAgentPrompted(
            invocationId: $link->agent_conversation_id,
            conversationId: $link->agent_conversation_id,
            promptTokens: 120,
            completionTokens: 0,
        ));

        Event::assertDispatchedTimes(UsageRecorded::class, 1);
        $context = $this->assertSystemLogged('ai.usage.not_metered', fn (array $c) => $c['reason'] === 'zero_tokens'
            && $c['input']['team_id'] === $user->currentTeam->id
            && $c['input']['invocation_id'] === $link->agent_conversation_id
            && $c['calc']['direction'] === 'out');
        $this->assertNotNull($context);
        $this->assertSame(['debug'], array_column($this->systemLogEntries('ai.usage.not_metered'), 'level'));
    }

    public function test_usage_listener_flags_a_missing_token_meter_as_a_billing_gap(): void
    {
        Event::fake([UsageRecorded::class]);

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $link = AIConversationLink::factory()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
        ]);

        UsageMeter::query()->where('code', 'ai_tokens_out')->delete();

        event($this->fakeAgentPrompted(
            invocationId: $link->agent_conversation_id,
            conversationId: $link->agent_conversation_id,
            promptTokens: 90,
            completionTokens: 40,
        ));

        Event::assertDispatched(UsageRecorded::class, fn (UsageRecorded $e) => $e->meterCode === 'ai_tokens_in');
        Event::assertNotDispatched(UsageRecorded::class, fn (UsageRecorded $e) => $e->meterCode === 'ai_tokens_out');

        $this->assertSystemLogged('ai.usage.not_metered', fn (array $c) => $c['outcome'] === 'degraded'
            && $c['reason'] === 'meter_missing'
            && $c['input']['team_id'] === $team->id
            && $c['calc']['direction'] === 'out'
            && $c['calc']['meter_code'] === 'ai_tokens_out'
            && $c['calc']['tokens'] === 40);
        $this->assertSame('warning', $this->systemLogEntries('ai.usage.not_metered')[0]['level']);
        $this->assertNoSensitiveDataLogged();
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
        $response = new AgentResponse($invocationId, 'streamed text', new TextUsage(inputTokens: 100, outputTokens: 25), new Meta('openai', 'gpt-test'));
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

        $this->assertCount(2, $this->systemLogEntries('billing.usage.recorded'));
        $this->assertCount(2, $this->systemLogEntries('billing.usage.duplicate_ignored'));
        $this->assertSystemLogged('billing.usage.duplicate_ignored', fn (array $c) => $c['reason'] === 'event_key_exists'
            && $c['input']['event_key'] === 'ai_tokens_in:sdk:'.$link->agent_conversation_id
            && $c['calc']['quantity'] === 100);
    }

    private function fakeAgentPrompted(
        string $invocationId,
        ?string $conversationId,
        int $promptTokens,
        int $completionTokens,
    ): AgentPrompted {
        $usage = new TextUsage(inputTokens: $promptTokens, outputTokens: $completionTokens);
        $meta = new Meta('openai', 'gpt-test');

        $response = new AgentResponse($invocationId, 'fake text', $usage, $meta);

        if ($conversationId !== null) {
            $response->withinConversation($conversationId, (object) ['id' => 0]);
        }

        return new AgentPrompted($invocationId, Mockery::mock(AgentPrompt::class), $response);
    }
}
