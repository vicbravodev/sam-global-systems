<?php

namespace Tests\Feature\Domains\Copilot;

use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Copilot\Models\CopilotMessage;
use App\Domains\Copilot\Streaming\CopilotStreamState;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Infrastructure\AI\Agents\CopilotAgent;
use Database\Seeders\AccessSeeder;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

/**
 * What the stream does when the provider fails or the browser leaves:
 * before the first token it answers deterministically in the same stream;
 * after it, or on a disconnect, it keeps what was generated as partial.
 */
class CopilotFallbackTest extends TestCase
{
    use AssertsSystemLog, CopilotFixtures, RefreshDatabase, StreamsCopilotParts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
        $this->seed(AIMeterSeeder::class);
        config(['ai.providers.openai.key' => 'test-key']);
    }

    public function test_error_before_first_token_falls_back_inside_the_stream(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team);

        CopilotAgent::fake(fn () => throw new ProviderOverloadedException('down'));

        $question = '¿Dónde está T555, Juan?';
        $response = $this->streamAs($user, $team->slug, ['content' => $question])->assertOk();
        $parts = $this->parts($response);
        $types = $this->types($parts);

        $this->assertSame(['start', 'start-step', 'data-copilot-blocks', 'text-start', 'text-delta', 'text-end', 'finish-step', 'data-copilot-message', 'finish'], $types);
        $this->assertStringEndsWith("data: [DONE]\n\n", $response->streamedContent());

        $answer = $this->firstPart($parts, 'data-copilot-message')['data']['answer'];
        $this->assertStringContainsString('T555', $answer['content']);
        $this->assertFalse($answer['partial']);
        $this->assertSame('deterministic', CopilotMessage::query()->findOrFail($answer['id'])->context_json['mode']);
        $this->assertDatabaseCount('copilot_messages', 2);

        $this->assertSystemLogged('copilot.turn.fallback', fn (array $c) => $c['outcome'] === 'degraded'
            && $c['reason'] === 'agent_error_before_output'
            && isset($c['error']));
        $this->assertSystemLogged('copilot.turn.completed', fn (array $c) => $c['input']['mode'] === 'deterministic');
        $this->assertSystemNotLogged('copilot.turn.failed');
        $this->assertNoSensitiveDataLogged();
        $this->assertQuestionNeverLogged($question);
    }

    public function test_error_after_a_tool_step_but_before_text_still_falls_back(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team, 'T555');
        $this->truckWithTelemetry($team, 'T600');

        CopilotAgent::fake([
            new ToolCall('c1', 'rank_assets', ['metric' => 'fuel_used_pct']),
            fn () => throw new ProviderOverloadedException('down'),
        ]);

        $parts = $this->parts($this->streamAs($user, $team->slug, ['content' => '¿Dónde está T555?'])->assertOk());
        $types = $this->types($parts);

        // One start only: the fallback continues the stream the agent opened.
        $this->assertSame(1, count(array_keys($types, 'start')));
        $this->assertNotContains('error', $types);
        $this->assertSame(['finish-step', 'data-copilot-message', 'finish'], array_slice($types, -3));

        // The ranking card already went out with its tool; the deterministic
        // answer follows it and the stored message (authoritative) drops it.
        $blocks = array_values(array_filter($parts, fn (array $p) => $p['type'] === 'data-copilot-blocks'));
        $this->assertSame(['rank_assets', 'deterministic'], array_column(array_column($blocks, 'data'), 'tool'));

        $answer = $this->firstPart($parts, 'data-copilot-message')['data']['answer'];
        $this->assertSame(['asset_location'], array_column($answer['tools'], 'tool'));
        $this->assertNotContains('ranking', array_column($answer['blocks'], 'type'));
        $this->assertSystemLogged('copilot.turn.fallback', fn (array $c) => $c['reason'] === 'agent_error_before_output');
    }

    public function test_error_mid_stream_persists_partial_answer(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team);

        StepFakeTextGateway::fake(CopilotAgent::class, [
            new StepResponse('Reviso la flota.', [new ToolCall('c1', 'fleet_overview', [])], FinishReason::ToolCalls, new TextUsage, new Meta('openai', 'gpt-test')),
            fn () => throw new ProviderOverloadedException('x'),
        ]);

        $question = '¿Cómo va la flota, Juan?';
        $response = $this->streamAs($user, $team->slug, ['content' => $question])->assertOk();
        $parts = $this->parts($response);
        $types = $this->types($parts);

        $this->assertSame(['type' => 'error', 'errorText' => 'No pude completar la respuesta.'], end($parts));
        $this->assertSame(1, count(array_keys($types, 'error')));
        $this->assertNotContains('finish', $types);
        $this->assertLessThan(array_search('error', $types), array_search('data-copilot-message', $types));
        $this->assertStringEndsWith("data: [DONE]\n\n", $response->streamedContent());

        $answer = $this->firstPart($parts, 'data-copilot-message')['data']['answer'];
        $this->assertTrue($answer['partial']);
        $this->assertSame('Reviso la flota.', $answer['content']);

        $message = CopilotMessage::query()->findOrFail($answer['id']);
        $this->assertTrue($message->context_json['partial']);
        $this->assertSame('agent', $message->context_json['mode']);
        $this->assertSame(['fleet_overview'], array_column($message->tools_json, 'tool'));
        $this->assertDatabaseCount('copilot_messages', 2);

        $this->assertSystemLogged('copilot.turn.failed', fn (array $c) => $c['outcome'] === 'failed'
            && $c['reason'] === 'agent_error_mid_stream'
            && $c['input']['message_id'] === $message->id
            && isset($c['error']));
        $this->assertSystemLogged('copilot.turn.completed', fn (array $c) => $c['input']['partial'] === true);
        $this->assertSystemNotLogged('copilot.turn.fallback');
        $this->assertNoSensitiveDataLogged();
        $this->assertQuestionNeverLogged($question);
    }

    public function test_client_disconnect_persists_partial_answer(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team);

        $calls = 0;
        StepFakeTextGateway::fake(CopilotAgent::class, [
            new StepResponse('Reviso la flota ahora.', [new ToolCall('c1', 'fleet_overview', [])], FinishReason::ToolCalls, new TextUsage, new Meta('openai', 'gpt-test')),
            function () use (&$calls) {
                $calls++;

                return 'Todo en orden.';
            },
        ]);

        // The browser leaves right after the first text delta reached it.
        $state = new CopilotStreamState;
        $state->aborted = fn (): bool => $state->textStarted;
        $this->app->instance(CopilotStreamState::class, $state);

        $question = '¿Cómo va la flota, Juan?';
        $parts = $this->parts($this->streamAs($user, $team->slug, ['content' => $question])->assertOk());
        $types = $this->types($parts);

        $this->assertSame(1, count(array_keys($types, 'text-delta')));
        $this->assertNotContains('finish', $types);
        $this->assertNotContains('data-copilot-message', $types);
        $this->assertSame(0, $calls, 'the agent stopped with the stream');

        $message = CopilotMessage::query()->where('role', 'assistant')->sole();
        $this->assertTrue($message->context_json['partial']);
        $this->assertSame('Reviso', $message->content);
        $this->assertDatabaseCount('copilot_messages', 2);

        $this->assertSystemLogged('copilot.turn.failed', fn (array $c) => $c['outcome'] === 'skipped'
            && $c['reason'] === 'client_disconnected'
            && $c['input']['message_id'] === $message->id);
        $this->assertNoSensitiveDataLogged();
        $this->assertQuestionNeverLogged($question);
    }

    public function test_disconnect_after_the_answer_was_stored_does_not_store_it_twice(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team);

        CopilotAgent::fake(['La flota va bien.']);

        $state = new CopilotStreamState;
        $state->aborted = fn (): bool => $state->persisted;
        $this->app->instance(CopilotStreamState::class, $state);

        $this->parts($this->streamAs($user, $team->slug, ['content' => '¿Cómo va la flota?'])->assertOk());

        $message = CopilotMessage::query()->where('role', 'assistant')->sole();
        $this->assertSame('La flota va bien.', $message->content);
        $this->assertArrayNotHasKey('partial', $message->context_json);
        $this->assertSame(1, count($this->systemLogEntries('copilot.turn.completed')));
        $this->assertSystemNotLogged('copilot.turn.failed');
    }

    public function test_store_failing_after_commit_never_falls_back_nor_stores_twice(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team);

        // The answer commits and is metered; then the audit throws.
        $this->mock(RecordAuditEntry::class, fn (MockInterface $mock) => $mock->shouldReceive('execute')->andThrow(new RuntimeException('audit down')));

        // A tool step and an empty final step: no text ever reached the browser.
        CopilotAgent::fake([new ToolCall('c1', 'asset_location', ['asset_code' => 'T555']), '']);

        $question = '¿Dónde está T555, Juan?';
        $parts = $this->parts($this->streamAs($user, $team->slug, ['content' => $question])->assertOk());
        $types = $this->types($parts);

        $this->assertNotContains('text-delta', $types);
        $this->assertSame(['type' => 'error', 'errorText' => 'No pude completar la respuesta.'], end($parts));
        $this->assertNotContains('data-copilot-message', $types);

        $message = CopilotMessage::query()->where('role', 'assistant')->sole();
        $this->assertSame('agent', $message->context_json['mode']);
        $this->assertSame(1, UsageEvent::query()->where('event_key', 'like', 'copilot_queries:copilot:%')->count());
        $this->assertSame(2, $message->conversation->refresh()->messages_count);

        $this->assertSystemNotLogged('copilot.turn.fallback');
        $this->assertSystemLogged('copilot.turn.failed', fn (array $c) => $c['outcome'] === 'failed'
            && $c['reason'] === 'persist_failed'
            && $c['input']['message_id'] === $message->id
            && isset($c['error']));
        $this->assertNoSensitiveDataLogged();
        $this->assertQuestionNeverLogged($question);
    }

    public function test_store_failing_after_text_logs_persist_failed(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team);
        $this->mock(RecordAuditEntry::class, fn (MockInterface $mock) => $mock->shouldReceive('execute')->andThrow(new RuntimeException('audit down')));

        CopilotAgent::fake(['La flota va bien.']);

        $parts = $this->parts($this->streamAs($user, $team->slug, ['content' => '¿Cómo va la flota?'])->assertOk());

        $this->assertSame(['type' => 'error', 'errorText' => 'No pude completar la respuesta.'], end($parts));
        $this->assertSame(1, CopilotMessage::query()->where('role', 'assistant')->count());
        $this->assertSystemLogged('copilot.turn.failed', fn (array $c) => $c['reason'] === 'persist_failed');
        $this->assertSystemNotLogged('copilot.turn.fallback');
    }

    public function test_tool_error_text_never_reaches_the_browser(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team);

        // #[MaxSteps(6)]: the tool call of the last step is never run and
        // comes back as a failed tool result with the SDK's own message.
        CopilotAgent::fake(array_map(fn (int $i) => new ToolCall("c{$i}", 'fleet_overview', []), range(1, 6)));

        $parts = $this->parts($this->streamAs($user, $team->slug, ['content' => '¿Cómo va la flota?'])->assertOk());

        $errors = array_values(array_filter($parts, fn (array $p) => $p['type'] === 'tool-output-error'));
        $this->assertNotEmpty($errors);
        $this->assertSame(['No pude consultar esos datos.'], array_values(array_unique(array_column($errors, 'errorText'))));
    }
}
