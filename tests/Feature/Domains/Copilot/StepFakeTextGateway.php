<?php

namespace Tests\Feature\Domains\Copilot;

use Closure;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Gateway\StepResponse;

/**
 * FakeTextGateway that also accepts raw StepResponse items. The stock fake
 * cannot express a step that carries text AND tool calls (a ToolCall step
 * always has empty text), which is how real models often answer and call
 * suggest_followups in the same step.
 */
final class StepFakeTextGateway extends FakeTextGateway
{
    /**
     * Registers this gateway as the fake of `$agent` on the AI manager.
     *
     * @param  class-string  $agent
     * @param  array<int, mixed>  $responses
     */
    public static function fake(string $agent, array $responses): self
    {
        $gateway = new self($responses);
        $manager = app(AiManager::class);

        Closure::bind(fn () => $this->fakeAgentGateways[$agent] = $gateway, $manager, AiManager::class)();

        return $gateway;
    }

    protected function toStepResponse(mixed $response, TextProvider $provider, string $model): StepResponse
    {
        return $response instanceof StepResponse ? $response : parent::toStepResponse($response, $provider, $model);
    }
}
