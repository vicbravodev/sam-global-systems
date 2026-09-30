<?php

namespace Tests\Feature\Domains\Copilot;

use App\Domains\Copilot\Models\CopilotConversation;
use App\Domains\Copilot\Models\CopilotMessage;
use App\Infrastructure\AI\Agents\CopilotAgent;
use Database\Seeders\AccessSeeder;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\TextResponse;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * POST /{team}/copilot/stream: the agent turn over the Vercel data stream
 * protocol, with the Copilot data parts (cards, follow-ups and the stored
 * message) and the same policy, ownership and throttle as messages.store.
 */
class CopilotStreamEndpointTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, CopilotFixtures, RefreshDatabase, StreamsCopilotParts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
        $this->seed(AIMeterSeeder::class);
        config([
            'ai.providers.openai.key' => 'test-key',
            'ai.pricing' => ['gpt-test' => ['input' => 1.0, 'output' => 4.0]],
        ]);
    }

    public function test_streams_blocks_before_text_and_message_before_finish(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team);

        CopilotAgent::fake([
            new ToolCall('c1', 'asset_location', ['asset_code' => 'T555']),
            new ToolCall('c2', 'suggest_followups', ['questions' => ['¿Y su combustible?']]),
            new TextResponse('T555 va en ruta.', new TextUsage(inputTokens: 300, outputTokens: 20), new Meta('openai', 'gpt-test')),
        ]);

        $question = '¿Dónde está T555, Juan?';
        $response = $this->streamAs($user, $team->slug, ['content' => $question]);

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8')
            ->assertHeader('X-Accel-Buffering', 'no')
            ->assertHeader('x-vercel-ai-ui-message-stream', 'v1');

        $parts = $this->parts($response);
        $types = $this->types($parts);

        $this->assertSame('start', $types[0]);
        $this->assertSame('start-step', $types[1]);
        $this->assertSame(1, count(array_keys($types, 'start')));
        $this->assertLessThan(array_search('text-delta', $types), array_search('data-copilot-blocks', $types));
        $this->assertLessThan(array_search('data-copilot-message', $types), array_search('data-copilot-followups', $types));
        $this->assertLessThan(array_search('finish', $types), array_search('data-copilot-message', $types));
        $this->assertSame('finish', end($types));
        $this->assertNotContains('error', $types);
        $this->assertStringEndsWith("data: [DONE]\n\n", $response->streamedContent());

        $blocks = $this->firstPart($parts, 'data-copilot-blocks')['data'];
        $this->assertSame('c1', $blocks['toolCallId']);
        $this->assertSame('asset_location', $blocks['tool']);
        $this->assertNotSame('', $blocks['label']);
        $this->assertNotEmpty($blocks['blocks']);

        $this->assertSame(['questions' => ['¿Y su combustible?']], $this->firstPart($parts, 'data-copilot-followups')['data']);

        $message = $this->firstPart($parts, 'data-copilot-message')['data'];
        $this->assertSame('T555 va en ruta.', $message['answer']['content']);
        $this->assertFalse($message['answer']['partial']);
        $this->assertSame($question, $message['question']['content']);
        $this->assertSame(2, $message['conversation']['messagesCount']);
        $this->assertArrayHasKey('quota', $message);

        $this->assertSame('stop', $this->firstPart($parts, 'finish')['finishReason']);

        $this->assertDatabaseCount('copilot_messages', 2);
        $stored = CopilotMessage::query()->findOrFail($message['answer']['id']);
        $this->assertSame('agent', $stored->context_json['mode']);
        $this->assertSame(['asset_location'], array_column($stored->tools_json, 'tool'));
        $this->assertSame(300, $stored->input_tokens);

        $this->assertSystemLogged('copilot.turn.completed', fn (array $c) => $c['input']['mode'] === 'agent'
            && $c['input']['partial'] === false
            && is_int($c['result']['first_text_ms'])
            // A stream reports the model of its StreamStart: the agent's resolved model.
            && is_string($c['result']['model']));
        $this->assertSystemNotLogged('copilot.turn.fallback');
        $this->assertSystemNotLogged('copilot.turn.failed');
        $this->assertNoSensitiveDataLogged();
        $this->assertQuestionNeverLogged($question);
    }

    public function test_persisted_answer_joins_the_text_of_every_step(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team);

        StepFakeTextGateway::fake(CopilotAgent::class, [
            new StepResponse('Busco la unidad.', [new ToolCall('c1', 'asset_location', ['asset_code' => 'T555'])], FinishReason::ToolCalls, new TextUsage, new Meta('openai', 'gpt-test')),
            new TextResponse('Está en Insurgentes Sur.', new TextUsage, new Meta('openai', 'gpt-test')),
        ]);

        $parts = $this->parts($this->streamAs($user, $team->slug, ['content' => '¿Dónde está T555?'])->assertOk());

        $this->assertSame("Busco la unidad.\n\nEstá en Insurgentes Sur.", $this->firstPart($parts, 'data-copilot-message')['data']['answer']['content']);
        $this->assertSame(1, count(array_keys($this->types($parts), 'start')));
        $this->assertContains('finish-step', $this->types($parts));
    }

    public function test_tool_output_never_exposes_raw_facts(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team);

        CopilotAgent::fake([
            new ToolCall('c1', 'asset_location', ['asset_code' => 'T555']),
            'T555 va en ruta.',
        ]);

        $parts = $this->parts($this->streamAs($user, $team->slug, ['content' => '¿Dónde está T555?'])->assertOk());

        $input = $this->firstPart($parts, 'tool-input-available');
        $this->assertSame(['type' => 'tool-input-available', 'toolCallId' => 'c1', 'toolName' => 'asset_location', 'input' => ['asset_code' => 'T555']], $input);

        $outputs = array_values(array_filter($parts, fn (array $p) => $p['type'] === 'tool-output-available'));
        $this->assertCount(1, $outputs);
        $this->assertSame(['type' => 'tool-output-available', 'toolCallId' => 'c1', 'output' => ['ok' => true]], $outputs[0]);
        // The facts travel only to the model: the browser gets the cards.
        $this->assertStringNotContainsString('"facts"', collect($parts)->reject(fn (array $p) => $p['type'] === 'data-copilot-message')->toJson());
    }

    public function test_foreign_conversation_is_not_found_before_any_stream(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        [$stranger] = $this->memberWithRole('supervisor');
        $foreign = CopilotConversation::factory()->create(['user_id' => $stranger->id, 'team_id' => $stranger->current_team_id]);

        CopilotAgent::fake(['no debe usarse']);

        $this->streamAs($user, $team->slug, ['content' => 'hola', 'conversation_id' => $foreign->id])
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');

        CopilotAgent::assertNeverPrompted();
        $this->assertSame(0, CopilotMessage::query()->count());
    }

    public function test_colleagues_conversation_is_forbidden(): void
    {
        [$owner, $team] = $this->memberWithRole('supervisor');
        $conversation = CopilotConversation::factory()->create(['user_id' => $owner->id, 'team_id' => $team->id]);
        $colleague = $this->joinTeamWithRole($team, 'supervisor');

        $this->streamAs($colleague, $team->slug, ['content' => 'hola', 'conversation_id' => $conversation->id])
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/json');

        $this->assertSame(0, CopilotMessage::query()->count());
    }

    public function test_viewer_without_copilot_access_is_forbidden(): void
    {
        [$user, $team] = $this->memberWithRole('viewer');

        $this->streamAs($user, $team->slug, ['content' => '¿Dónde está T555?'])
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/json');

        $this->assertSame(0, CopilotMessage::query()->count());
    }

    public function test_invalid_payload_is_rejected_as_json_not_redirected(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');

        $this->streamAs($user, $team->slug, ['content' => ''])
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonValidationErrors('content');
    }

    public function test_stream_is_rate_limited(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');

        // Invalid payloads still count: the throttle runs before validation.
        foreach (range(1, 20) as $i) {
            $this->streamAs($user, $team->slug, ['content' => ''])->assertUnprocessable();
        }

        $this->streamAs($user, $team->slug, ['content' => '¿Dónde está T555?'])
            ->assertTooManyRequests()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_deterministic_mode_streams_the_same_protocol(): void
    {
        config(['ai.providers.openai.key' => '']);
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team);
        CopilotAgent::fake(['no debe usarse']);

        $question = '¿Dónde está T555, Juan?';
        $response = $this->streamAs($user, $team->slug, ['content' => $question]);

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8')
            ->assertHeader('X-Accel-Buffering', 'no')
            ->assertHeader('x-vercel-ai-ui-message-stream', 'v1');

        $parts = $this->parts($response);
        $types = $this->types($parts);

        CopilotAgent::assertNeverPrompted();
        $this->assertSame(['start', 'start-step', 'data-copilot-blocks', 'text-start', 'text-delta', 'text-end', 'finish-step', 'data-copilot-message', 'finish'], $types);
        $this->assertStringEndsWith("data: [DONE]\n\n", $response->streamedContent());

        $blocks = $this->firstPart($parts, 'data-copilot-blocks')['data'];
        $this->assertSame('deterministic', $blocks['tool']);
        $this->assertSame('location', $blocks['blocks'][0]['type']);

        $message = $this->firstPart($parts, 'data-copilot-message')['data'];
        $this->assertSame($this->firstPart($parts, 'text-delta')['delta'], $message['answer']['content']);
        $this->assertStringContainsString('T555', $message['answer']['content']);
        $this->assertSame(['asset_location'], array_column($message['answer']['tools'], 'tool'));

        $finish = $this->firstPart($parts, 'finish');
        $this->assertSame('stop', $finish['finishReason']);
        $this->assertSame(0, $finish['messageMetadata']['usage']['totalTokens']);

        $this->assertDatabaseCount('copilot_messages', 2);
        $this->assertSystemLogged('copilot.turn.fallback', fn (array $c) => $c['outcome'] === 'skipped' && $c['reason'] === 'no_provider_key');
        $this->assertSystemLogged('copilot.turn.completed', fn (array $c) => $c['input']['mode'] === 'deterministic');
        $this->assertNoSensitiveDataLogged();
        $this->assertQuestionNeverLogged($question);
    }

    public function test_stream_never_reads_another_tenants_unit(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        [, $other] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($other, 'T777');
        $this->truckWithTelemetry($team, 'A100');

        CopilotAgent::fake([
            new ToolCall('c1', 'asset_location', ['asset_code' => 'T777']),
            'No la encontré.',
        ]);

        $body = $this->assertNoTenantLeak($team, fn () => $this->streamAs($user, $team->slug, ['content' => '¿Dónde está T777?'])
            ->assertOk()
            ->streamedContent());

        $this->assertStringNotContainsString('Kenworth T777', $body);
        $this->assertStringNotContainsString('Insurgentes', $body);
        $this->assertStringNotContainsString('data-copilot-blocks', $body);
    }
}
