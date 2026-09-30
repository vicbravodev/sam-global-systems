<?php

namespace Tests\Feature\Domains\Copilot;

use App\Domains\Copilot\Enums\CopilotIntent;
use App\Domains\Copilot\Models\CopilotMessage;
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
use Laravel\Ai\Responses\TextResponse;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

/**
 * El turno JSON corre el agente con tools: persiste la traza real de tools,
 * las tarjetas, los followups y el uso de todos los pasos; si el proveedor
 * falla o no hay key, responde por el camino determinista.
 */
class CopilotAgentTurnTest extends TestCase
{
    use AssertsSystemLog, CopilotFixtures, RefreshDatabase;

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

    public function test_multi_step_turn_persists_tools_blocks_followups_and_usage(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team, 'T555');
        $this->truckWithTelemetry($team, 'T600');

        CopilotAgent::fake([
            new ToolCall('call_1', 'rank_assets', ['metric' => 'fuel_used_pct']),
            new ToolCall('call_2', 'asset_fuel', ['asset_code' => 'T555']),
            new ToolCall('call_3', 'suggest_followups', ['questions' => ['¿Y el ralentí de T555?', '¿Quién la maneja?']]),
            new TextResponse('**T555** consumió más combustible.', new TextUsage(inputTokens: 900, outputTokens: 120), new Meta('openai', 'gpt-test')),
        ]);

        $question = '¿Qué unidad gastó más combustible esta semana, Juan?';

        $answer = $this->actingAs($user)
            ->postJson("/{$team->slug}/copilot/messages", ['content' => $question])
            ->assertCreated()
            ->json('answer');

        $this->assertSame('**T555** consumió más combustible.', $answer['content']);
        // suggest_followups no es una tool de datos: sólo aporta sus preguntas.
        $this->assertSame(['rank_assets', 'asset_fuel'], array_column($answer['tools'], 'tool'));
        $this->assertSame(['ok', 'ok'], array_column($answer['tools'], 'status'));
        $this->assertArrayHasKey('durationMs', $answer['tools'][0]);
        $this->assertSame(CopilotIntent::AssetRanking->value, $answer['intent']);
        $this->assertSame(['¿Y el ralentí de T555?', '¿Quién la maneja?'], $answer['followups']);
        $this->assertFalse($answer['partial']);
        $this->assertNotEmpty($answer['blocks']);
        $this->assertSame('gpt-test', $answer['usage']['model']);

        $message = CopilotMessage::query()->findOrFail($answer['id']);
        $this->assertSame('agent', $message->context_json['mode']);
        $this->assertNotEmpty($message->context_json['facts_digest']);

        $this->assertSystemLogged('copilot.turn.started', fn (array $c) => $c['input']['mode'] === 'agent'
            && $c['input']['question_length'] === mb_strlen($question)
            && $c['input']['history_turns'] === 0);
        $this->assertSystemLogged('copilot.turn.completed', fn (array $c) => $c['input']['mode'] === 'agent'
            && $c['input']['partial'] === false
            && $c['calc']['tool_count'] === 2
            && $c['calc']['tools'] === ['rank_assets', 'asset_fuel']
            && $c['calc']['followups_count'] === 2
            && $c['calc']['steps'] >= 1
            && $c['result']['model'] === 'gpt-test');
        // The step guard narrates every step under the turn's tenant.
        $this->assertSystemLogged('copilot.step.started', fn (array $c) => $c['input']['team_id'] === $team->id);
        $this->assertNoSensitiveDataLogged();
        $this->assertQuestionNeverLogged($question);
    }

    public function test_open_question_uses_fleet_overview(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team, 'T555');

        CopilotAgent::fake([
            new ToolCall('call_1', 'fleet_overview', []),
            new TextResponse('La flota va bien: **1** unidad en ruta.', new TextUsage(inputTokens: 500, outputTokens: 60), new Meta('openai', 'gpt-test')),
        ]);

        $answer = $this->actingAs($user)
            ->postJson("/{$team->slug}/copilot/messages", ['content' => '¿Cómo vamos?'])
            ->assertCreated()
            ->json('answer');

        $this->assertSame('fleet_overview', $answer['tools'][0]['tool']);
        $this->assertSame(CopilotIntent::FleetOverview->value, $answer['intent']);
        $this->assertSame([], $answer['followups']);
    }

    public function test_provider_error_falls_back_to_deterministic_answer(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team, 'T555');

        CopilotAgent::fake(fn () => throw new ProviderOverloadedException('down'));

        $question = '¿Dónde está T555, Juan?';

        $answer = $this->actingAs($user)
            ->postJson("/{$team->slug}/copilot/messages", ['content' => $question])
            ->assertCreated()
            ->json('answer');

        $this->assertNotSame('', trim($answer['content']));
        $this->assertStringContainsString('T555', $answer['content']);
        $this->assertNull($answer['usage']['model']);
        $this->assertSame(0, $answer['usage']['inputTokens']);
        $this->assertSame(CopilotIntent::AssetLocation->value, $answer['intent']);
        $this->assertSame('location', collect($answer['blocks'])->firstWhere('type', 'location')['type']);
        $this->assertSame('deterministic', CopilotMessage::query()->findOrFail($answer['id'])->context_json['mode']);

        $this->assertSystemLogged('copilot.turn.fallback', fn (array $c) => $c['outcome'] === 'degraded'
            && $c['reason'] === 'agent_error_before_output'
            && isset($c['error']));
        $this->assertSystemLogged('copilot.turn.completed', fn (array $c) => $c['input']['mode'] === 'deterministic');
        $this->assertNoSensitiveDataLogged();
        $this->assertQuestionNeverLogged($question);
    }

    public function test_answer_given_alongside_suggest_followups_survives_an_empty_final_step(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team, 'T555');

        StepFakeTextGateway::fake(CopilotAgent::class, [
            new ToolCall('call_1', 'asset_location', ['asset_code' => 'T555']),
            // The model answers and calls suggest_followups in the same step…
            new StepResponse(
                'La **T555** va por Insurgentes Sur.',
                [new ToolCall('call_2', 'suggest_followups', ['questions' => ['¿Y su combustible?', '¿Quién la maneja?']])],
                FinishReason::ToolCalls,
                new TextUsage(inputTokens: 400, outputTokens: 40),
                new Meta('openai', 'gpt-test'),
            ),
            // …and the step after the tool result comes back empty.
            new TextResponse('', new TextUsage(inputTokens: 450, outputTokens: 1), new Meta('openai', 'gpt-test')),
        ]);

        $answer = $this->actingAs($user)
            ->postJson("/{$team->slug}/copilot/messages", ['content' => '¿Dónde está T555?'])
            ->assertCreated()
            ->json('answer');

        $this->assertSame('La **T555** va por Insurgentes Sur.', $answer['content']);
        $this->assertSame(['¿Y su combustible?', '¿Quién la maneja?'], $answer['followups']);
        $this->assertSame(850, $answer['usage']['inputTokens']);
    }

    public function test_texts_of_every_step_are_joined(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team, 'T555');

        StepFakeTextGateway::fake(CopilotAgent::class, [
            new StepResponse('Busco la unidad.', [new ToolCall('call_1', 'asset_location', ['asset_code' => 'T555'])], FinishReason::ToolCalls, new TextUsage, new Meta('openai', 'gpt-test')),
            new TextResponse('Está en Insurgentes Sur.', new TextUsage, new Meta('openai', 'gpt-test')),
        ]);

        $this->actingAs($user)
            ->postJson("/{$team->slug}/copilot/messages", ['content' => '¿Dónde está T555?'])
            ->assertCreated()
            ->assertJsonPath('answer.content', "Busco la unidad.\n\nEstá en Insurgentes Sur.");
    }

    public function test_steps_without_text_fall_back_to_the_tool_highlights(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team, 'T555');

        CopilotAgent::fake([
            new ToolCall('call_1', 'asset_location', ['asset_code' => 'T555']),
            new TextResponse('', new TextUsage(inputTokens: 300, outputTokens: 1), new Meta('openai', 'gpt-test')),
        ]);

        $content = $this->actingAs($user)
            ->postJson("/{$team->slug}/copilot/messages", ['content' => '¿Dónde está T555?'])
            ->assertCreated()
            ->json('answer.content');

        $this->assertNotSame('No pude completar la respuesta.', $content);
        $this->assertStringContainsString('Última posición de T555', $content);
        $this->assertStringContainsString('Av. Insurgentes Sur, CDMX', $content);
    }

    public function test_fallback_discards_what_the_agent_collected_before_failing(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team, 'T555');
        $this->truckWithTelemetry($team, 'T600');

        $calls = 0;
        CopilotAgent::fake(function () use (&$calls) {
            return $calls++ === 0
                ? new ToolCall('call_1', 'rank_assets', ['metric' => 'fuel_used_pct'])
                : throw new ProviderOverloadedException('down');
        });

        $answer = $this->actingAs($user)
            ->postJson("/{$team->slug}/copilot/messages", ['content' => '¿Dónde está T555?'])
            ->assertCreated()
            ->json('answer');

        $this->assertSame(2, $calls, 'the agent ran rank_assets before failing');
        $this->assertSame(['asset_location'], array_column($answer['tools'], 'tool'));
        $this->assertNotContains('ranking', array_column($answer['blocks'], 'type'));
        $this->assertSame(CopilotIntent::AssetLocation->value, $answer['intent']);
        $message = CopilotMessage::query()->findOrFail($answer['id']);
        $this->assertSame(['asset_location'], array_column($message->tools_json, 'tool'));
        $this->assertStringNotContainsString('rank_assets', (string) ($message->context_json['facts_digest'] ?? ''));
        $this->assertSystemLogged('copilot.turn.fallback', fn (array $c) => $c['reason'] === 'agent_error_before_output');
    }

    public function test_without_provider_key_uses_deterministic_mode(): void
    {
        config(['ai.providers.openai.key' => '']);
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team, 'T555');

        CopilotAgent::fake(['no debe usarse']);

        $answer = $this->actingAs($user)
            ->postJson("/{$team->slug}/copilot/messages", ['content' => '¿Dónde está T555?'])
            ->assertCreated()
            ->json('answer');

        CopilotAgent::assertNeverPrompted();
        $this->assertSame(['asset_location'], array_column($answer['tools'], 'tool'));
        $this->assertSame('ok', $answer['tools'][0]['status']);

        $this->assertSystemLogged('copilot.turn.started', fn (array $c) => $c['input']['mode'] === 'deterministic');
        $this->assertSystemLogged('copilot.turn.fallback', fn (array $c) => $c['outcome'] === 'skipped' && $c['reason'] === 'no_provider_key');
        $this->assertSystemLogged('copilot.turn.completed', fn (array $c) => $c['input']['mode'] === 'deterministic'
            && $c['calc']['steps'] === 0
            && $c['result']['model'] === null);
        $this->assertNoSensitiveDataLogged();
    }

    private function assertQuestionNeverLogged(string $question): void
    {
        foreach ($this->systemLogEntries() as $entry) {
            $json = json_encode($entry['context'], JSON_UNESCAPED_UNICODE);

            $this->assertStringNotContainsString($question, (string) $json, "[{$entry['code']}] registró la pregunta.");
            $this->assertStringNotContainsString('Juan', (string) $json, "[{$entry['code']}] registró un nombre.");
        }
    }
}
