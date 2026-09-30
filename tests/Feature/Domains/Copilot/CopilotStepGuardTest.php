<?php

namespace Tests\Feature\Domains\Copilot;

use App\Domains\Copilot\Data\CopilotTurnScope;
use App\Domains\Copilot\Support\CopilotTurnCollector;
use App\Domains\Copilot\Tools\Sdk\SuggestFollowupsTool;
use App\Infrastructure\AI\Agents\CopilotAgent;
use App\Infrastructure\AI\Middleware\CopilotStepGuard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\ToolChoice;
use Laravel\Ai\Tools\Request;
use ReflectionClass;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class CopilotStepGuardTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    private function pendingStep(int $number, bool $isFinalStep, ?TextUsage $usage = null, array $tools = []): PendingStep
    {
        return new PendingStep(
            number: $number,
            isFinalStep: $isFinalStep,
            provider: 'openai',
            model: 'gpt-test',
            instructions: '',
            messages: [],
            tools: $tools,
            schema: null,
            options: null,
            usage: $usage ?? new TextUsage,
        );
    }

    private function stepResult(): StepResult
    {
        return new StepResult(new StepResponse('ok', [], FinishReason::Stop, new TextUsage, new Meta));
    }

    public function test_final_step_forces_answer_without_tools(): void
    {
        $seen = null;
        $expected = $this->stepResult();

        $returned = (new CopilotStepGuard(60000))->handle($this->pendingStep(5, true), function (PendingStep $s) use (&$seen, $expected) {
            $seen = $s;

            return $expected;
        });

        $this->assertSame($expected, $returned);
        $this->assertSame(ToolChoice::none, $seen->options->toolChoice->mode);
        $this->assertSystemLogged('copilot.step.budget_reached', fn ($c) => $c['reason'] === 'max_steps'
            && $c['input']['step'] === 5
            && $c['calc']['tokens_so_far'] === 0
            && $c['calc']['max_turn_tokens'] === 60000);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_token_budget_forces_answer_early(): void
    {
        $seen = null;

        (new CopilotStepGuard(60000))->handle(
            $this->pendingStep(3, false, new TextUsage(inputTokens: 70000, outputTokens: 0)),
            function (PendingStep $s) use (&$seen) {
                $seen = $s;

                return $this->stepResult();
            },
        );

        $this->assertSame(ToolChoice::none, $seen->options->toolChoice->mode);
        $this->assertSystemLogged('copilot.step.budget_reached', fn ($c) => $c['reason'] === 'max_turn_tokens'
            && $c['input']['step'] === 3
            && $c['calc']['tokens_so_far'] === 70000
            && $c['calc']['max_turn_tokens'] === 60000);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_normal_step_passes_through_and_logs_debug(): void
    {
        $seen = null;
        $step = $this->pendingStep(2, false, tools: [new SuggestFollowupsTool(new CopilotTurnCollector)]);

        (new CopilotStepGuard(60000))->handle($step, function (PendingStep $s) use (&$seen) {
            $seen = $s;

            return $this->stepResult();
        });

        $this->assertSame($step, $seen);
        $this->assertNull($seen->options);
        $this->assertSystemLogged('copilot.step.started', fn ($c) => $c['input']['step'] === 2 && $c['input']['tools_available'] === 1);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_agent_wiring(): void
    {
        $scope = new CopilotTurnScope(1, 'acme', [], false, 'America/Mexico_City', CarbonImmutable::parse('2026-09-30 12:00:00', 'UTC'));
        $tool = new SuggestFollowupsTool(new CopilotTurnCollector);
        $guard = new CopilotStepGuard(60000);
        $agent = new CopilotAgent($scope, [['role' => 'user', 'content' => 'hola']], [$tool], $guard);

        $instructions = (string) $agent->instructions();
        $this->assertStringContainsString('2026-09-30T06:00:00-06:00', $instructions);
        $this->assertStringContainsString('America/Mexico_City', $instructions);
        $this->assertStringContainsString('suggest_followups', $instructions);
        $this->assertSame([$tool], $agent->tools());
        $this->assertSame([$guard], $agent->middleware());
        $this->assertCount(1, $agent->messages());

        $class = new ReflectionClass(CopilotAgent::class);
        $this->assertSame(6, $class->getAttributes(MaxSteps::class)[0]->newInstance()->value);
        $this->assertSame(45, $class->getAttributes(Timeout::class)[0]->newInstance()->value);
    }

    public function test_suggest_followups_trims_and_caps(): void
    {
        $collector = new CopilotTurnCollector;
        $tool = new SuggestFollowupsTool($collector);

        $this->assertSame('ok', (string) $tool->handle(new Request(['questions' => [' uno ', '', 'dos', 'tres', 'cuatro', str_repeat('x', 200)]], 'c')));
        $this->assertSame(['uno', 'dos', 'tres'], $collector->followupList());
        $this->assertSame('suggest_followups', $tool->name());
    }
}
