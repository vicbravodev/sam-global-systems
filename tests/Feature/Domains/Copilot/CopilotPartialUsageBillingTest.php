<?php

namespace Tests\Feature\Domains\Copilot;

use App\Domains\Copilot\Actions\PrepareCopilotTurn;
use App\Domains\Copilot\Actions\RecordCopilotUsage;
use App\Domains\Copilot\Models\CopilotMessage;
use App\Domains\Copilot\Streaming\CopilotStreamState;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Infrastructure\AI\Agents\CopilotAgent;
use App\Support\TenantContext;
use Database\Seeders\AccessSeeder;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
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
 * The tokens a Copilot turn spent before it failed or was cut short are
 * billed: on the partial answer (mid-stream error, browser gone) or apart,
 * keyed by the question, when the turn fell back to the deterministic path.
 * A completed turn keeps billing once, from the stream's own usage.
 */
class CopilotPartialUsageBillingTest extends TestCase
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

    public function test_agent_failure_before_text_bills_the_tokens_it_already_spent(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team);

        StepFakeTextGateway::fake(CopilotAgent::class, [
            $this->toolStep('', 200, 10),
            fn () => throw new ProviderOverloadedException('down'),
        ]);

        $question = '¿Cómo va la flota, Juan?';
        $parts = $this->parts($this->streamAs($user, $team->slug, ['content' => $question])->assertOk());

        $answer = $this->firstPart($parts, 'data-copilot-message')['data']['answer'];
        $questionId = $this->questionId();

        $this->assertSame('deterministic', CopilotMessage::query()->findOrFail($answer['id'])->context_json['mode']);
        $this->assertUsage($team->id, "ai_tokens_in:copilot:abandoned:{$questionId}", 200);
        $this->assertUsage($team->id, "ai_tokens_out:copilot:abandoned:{$questionId}", 10);
        // The deterministic answer bills the query once, and no tokens of its own.
        $this->assertUsage($team->id, "copilot_queries:copilot:{$answer['id']}", 1);
        $this->assertSame(0, UsageEvent::query()->where('event_key', 'like', "ai_tokens_%:copilot:{$answer['id']}")->count());
        $this->assertSame(3, UsageEvent::query()->count());

        $event = UsageEvent::query()->where('event_key', "ai_tokens_in:copilot:abandoned:{$questionId}")->sole();
        $this->assertSame('agent_error_before_output', $event->metadata_json['cause']);
        $this->assertSame($questionId, $event->metadata_json['copilot_question_message_id']);

        $this->assertSystemLogged('copilot.turn.fallback', fn (array $c) => $c['reason'] === 'agent_error_before_output'
            && $c['calc']['tokens_so_far'] === 210);
        $this->assertSystemLogged('copilot.turn.partial_usage', fn (array $c) => $c['outcome'] === 'ok'
            && $c['input']['cause'] === 'agent_error_before_output'
            && $c['input']['question_id'] === $questionId
            && $c['input']['message_id'] === null
            && $c['calc']['steps'] === 1
            && $c['result']['model'] === 'gpt-test'
            && $c['result']['input_tokens'] === 200
            && $c['result']['output_tokens'] === 10
            && $c['result']['cost_estimate'] > 0);
        $this->assertNoSensitiveDataLogged();
        $this->assertQuestionNeverLogged($question);
    }

    public function test_agent_failure_mid_stream_bills_the_spent_tokens_on_the_partial_answer(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team);

        StepFakeTextGateway::fake(CopilotAgent::class, [
            $this->toolStep('Reviso la flota.', 120, 30),
            fn () => throw new ProviderOverloadedException('x'),
        ]);

        $question = '¿Cómo va la flota, Juan?';
        $parts = $this->parts($this->streamAs($user, $team->slug, ['content' => $question])->assertOk());

        $answer = CopilotMessage::query()->findOrFail($this->firstPart($parts, 'data-copilot-message')['data']['answer']['id']);
        $this->assertTrue($answer->context_json['partial']);
        $this->assertSame(120, $answer->input_tokens);
        $this->assertSame(30, $answer->output_tokens);
        $this->assertSame('gpt-test', $answer->model);

        $this->assertUsage($team->id, "ai_tokens_in:copilot:{$answer->id}", 120);
        $this->assertUsage($team->id, "ai_tokens_out:copilot:{$answer->id}", 30);
        $this->assertUsage($team->id, "copilot_queries:copilot:{$answer->id}", 1);
        $this->assertSame(0, UsageEvent::query()->where('event_key', 'like', '%:abandoned:%')->count());

        // A retry of the metering never bills twice.
        app(RecordCopilotUsage::class)->execute($answer);
        $this->assertSame(3, UsageEvent::query()->count());

        $this->assertSystemLogged('copilot.turn.failed', fn (array $c) => $c['reason'] === 'agent_error_mid_stream'
            && $c['calc']['tokens_so_far'] === 150);
        $this->assertSystemLogged('copilot.turn.partial_usage', fn (array $c) => $c['outcome'] === 'ok'
            && $c['input']['cause'] === 'agent_error_mid_stream'
            && $c['input']['message_id'] === $answer->id
            && $c['result']['input_tokens'] === 120
            && $c['result']['output_tokens'] === 30);
        $this->assertSystemNotLogged('copilot.turn.fallback');
        $this->assertNoSensitiveDataLogged();
        $this->assertQuestionNeverLogged($question);
    }

    public function test_client_disconnect_bills_the_steps_completed_before_it(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team);

        StepFakeTextGateway::fake(CopilotAgent::class, [
            $this->toolStep('Reviso la flota ahora.', 100, 20),
            new TextResponse('Todo en orden por ahora.', new TextUsage(inputTokens: 500, outputTokens: 50), new Meta('openai', 'gpt-test')),
        ]);

        // The browser leaves once the second step started writing.
        $state = new CopilotStreamState;
        $state->aborted = fn (): bool => str_contains($state->text, "\n\n");
        $this->app->instance(CopilotStreamState::class, $state);

        $question = '¿Cómo va la flota, Juan?';
        $parts = $this->parts($this->streamAs($user, $team->slug, ['content' => $question])->assertOk());
        $this->assertNotContains('finish', $this->types($parts));

        $answer = CopilotMessage::query()->where('role', 'assistant')->sole();
        $this->assertTrue($answer->context_json['partial']);
        // Only the completed first step: the cut second one never reported usage.
        $this->assertSame(100, $answer->input_tokens);
        $this->assertSame(20, $answer->output_tokens);

        $this->assertUsage($team->id, "ai_tokens_in:copilot:{$answer->id}", 100);
        $this->assertUsage($team->id, "ai_tokens_out:copilot:{$answer->id}", 20);
        $this->assertSame(3, UsageEvent::query()->count());

        $this->assertSystemLogged('copilot.turn.failed', fn (array $c) => $c['reason'] === 'client_disconnected'
            && $c['calc']['tokens_so_far'] === 120);
        $this->assertSystemLogged('copilot.turn.partial_usage', fn (array $c) => $c['outcome'] === 'ok'
            && $c['input']['cause'] === 'client_disconnected'
            && $c['input']['message_id'] === $answer->id
            && $c['calc']['steps'] === 1);
        $this->assertNoSensitiveDataLogged();
        $this->assertQuestionNeverLogged($question);
    }

    public function test_completed_turn_bills_the_stream_usage_once(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team);

        StepFakeTextGateway::fake(CopilotAgent::class, [
            $this->toolStep('Busco la unidad.', 100, 20),
            new TextResponse('Está en Insurgentes Sur.', new TextUsage(inputTokens: 50, outputTokens: 5), new Meta('openai', 'gpt-test')),
        ]);

        $parts = $this->parts($this->streamAs($user, $team->slug, ['content' => '¿Dónde está T555?'])->assertOk());
        $answerId = $this->firstPart($parts, 'data-copilot-message')['data']['answer']['id'];

        $this->assertUsage($team->id, "ai_tokens_in:copilot:{$answerId}", 150);
        $this->assertUsage($team->id, "ai_tokens_out:copilot:{$answerId}", 25);
        $this->assertUsage($team->id, "copilot_queries:copilot:{$answerId}", 1);
        $this->assertSame(3, UsageEvent::query()->count());
        $this->assertSystemNotLogged('copilot.turn.partial_usage');
    }

    public function test_nothing_is_billed_for_an_agent_that_spent_no_tokens(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team);

        CopilotAgent::fake(fn () => throw new ProviderOverloadedException('down'));

        $parts = $this->parts($this->streamAs($user, $team->slug, ['content' => '¿Dónde está T555?'])->assertOk());
        $answerId = $this->firstPart($parts, 'data-copilot-message')['data']['answer']['id'];

        $this->assertSame(0, UsageEvent::query()->where('event_key', 'like', 'ai_tokens_%')->count());
        $this->assertUsage($team->id, "copilot_queries:copilot:{$answerId}", 1);
        $this->assertSystemLogged('copilot.turn.fallback', fn (array $c) => $c['calc']['tokens_so_far'] === 0);
        $this->assertSystemLogged('copilot.turn.partial_usage', fn (array $c) => $c['outcome'] === 'skipped'
            && $c['reason'] === 'no_tokens'
            && $c['input']['cause'] === 'agent_error_before_output');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_disconnect_before_any_step_completed_bills_no_tokens(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team);

        StepFakeTextGateway::fake(CopilotAgent::class, [$this->toolStep('Reviso la flota ahora.', 100, 20), 'Todo en orden.']);

        $state = new CopilotStreamState;
        $state->aborted = fn (): bool => $state->textStarted;
        $this->app->instance(CopilotStreamState::class, $state);

        $this->parts($this->streamAs($user, $team->slug, ['content' => '¿Cómo va la flota?'])->assertOk());

        $answer = CopilotMessage::query()->where('role', 'assistant')->sole();
        $this->assertSame(0, $answer->input_tokens);
        $this->assertSame(0, UsageEvent::query()->where('event_key', 'like', 'ai_tokens_%')->count());
        $this->assertSystemLogged('copilot.turn.partial_usage', fn (array $c) => $c['outcome'] === 'skipped'
            && $c['reason'] === 'no_tokens'
            && $c['input']['cause'] === 'client_disconnected');
    }

    public function test_billing_an_abandoned_agent_again_is_idempotent_and_stays_in_its_tenant(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        [$otherUser, $other] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team);
        $this->truckWithTelemetry($other, 'T777');

        // The other tenant already has usage of its own: it must stay untouched.
        StepFakeTextGateway::fake(CopilotAgent::class, [
            $this->toolStep('', 70, 7),
            fn () => throw new ProviderOverloadedException('down'),
        ]);
        $this->parts($this->streamAs($otherUser, $other->slug, ['content' => '¿Cómo va la flota?'])->assertOk());
        $otherEvents = UsageEvent::withoutGlobalScopes()->where('team_id', $other->id)->orderBy('id')->get()->toArray();
        $this->assertNotEmpty($otherEvents);

        $turn = TenantContext::for($team->id, fn () => app(PrepareCopilotTurn::class)->execute($team, $user, ['copilot.use'], '¿Cómo va la flota?', null, [], 'page'));
        $turn->spent->add(new TextUsage(inputTokens: 200, outputTokens: 10), 'gpt-test');

        $this->assertNoTenantLeak($team, function () use ($turn): void {
            app(RecordCopilotUsage::class)->executeAbandoned($turn);
            app(RecordCopilotUsage::class)->executeAbandoned($turn);
        });

        $mine = UsageEvent::withoutGlobalScopes()->where('team_id', $team->id)->get();
        $this->assertCount(2, $mine);
        $this->assertSame(
            ["ai_tokens_in:copilot:abandoned:{$turn->question->id}" => 200, "ai_tokens_out:copilot:abandoned:{$turn->question->id}" => 10],
            $mine->sortBy('event_key')->pluck('quantity', 'event_key')->all(),
        );
        $this->assertSame($otherEvents, UsageEvent::withoutGlobalScopes()->where('team_id', $other->id)->orderBy('id')->get()->toArray());
    }

    public function test_stream_consumed_outside_any_tenant_bills_the_abandoned_agent_to_its_team(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        [, $other] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team);

        StepFakeTextGateway::fake(CopilotAgent::class, [
            $this->toolStep('', 200, 10),
            fn () => throw new ProviderOverloadedException('down'),
        ]);

        $response = $this->streamAs($user, $team->slug, ['content' => '¿Cómo va la flota?']);

        // The body runs after the controller returned: no tenant, no session user.
        Context::flush();
        Auth::forgetGuards();
        $this->assertNull(TenantContext::id());

        $this->parts($response);

        $abandoned = UsageEvent::withoutGlobalScopes()->where('event_key', 'like', '%:abandoned:%')->get();
        $this->assertCount(2, $abandoned);
        $this->assertSame([$team->id], $abandoned->pluck('team_id')->unique()->values()->all());
        $this->assertSame(0, UsageEvent::withoutGlobalScopes()->where('team_id', $other->id)->count());
        $this->assertNull(TenantContext::id());
    }

    public function test_json_turn_falling_back_bills_the_abandoned_agent(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team);

        StepFakeTextGateway::fake(CopilotAgent::class, [
            $this->toolStep('', 300, 40),
            fn () => throw new ProviderOverloadedException('down'),
        ]);

        $response = $this->actingAs($user)
            ->postJson("/{$team->slug}/copilot/messages", ['content' => '¿Cómo va la flota?'])
            ->assertCreated();

        $questionId = $response->json('question.id');
        $this->assertUsage($team->id, "ai_tokens_in:copilot:abandoned:{$questionId}", 300);
        $this->assertUsage($team->id, "ai_tokens_out:copilot:abandoned:{$questionId}", 40);
        $this->assertUsage($team->id, 'copilot_queries:copilot:'.$response->json('answer.id'), 1);
        $this->assertSystemLogged('copilot.turn.fallback', fn (array $c) => $c['calc']['tokens_so_far'] === 340);
        $this->assertSystemLogged('copilot.turn.partial_usage', fn (array $c) => $c['outcome'] === 'ok'
            && $c['input']['question_id'] === $questionId);
        $this->assertNoSensitiveDataLogged();
    }

    /**
     * A completed agent step that called a tool, with its usage.
     */
    private function toolStep(string $text, int $input, int $output): StepResponse
    {
        return new StepResponse(
            $text,
            [new ToolCall('c1', 'fleet_overview', [])],
            FinishReason::ToolCalls,
            new TextUsage(inputTokens: $input, outputTokens: $output),
            new Meta('openai', 'gpt-test'),
        );
    }

    private function questionId(): int
    {
        return (int) CopilotMessage::query()->where('role', 'user')->sole()->id;
    }

    private function assertUsage(int $teamId, string $eventKey, int $quantity): void
    {
        $this->assertDatabaseHas('usage_events', ['team_id' => $teamId, 'event_key' => $eventKey, 'quantity' => $quantity]);
    }
}
