<?php

namespace App\Infrastructure\AI\Listeners;

use App\Support\SystemLog;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Prompts\AgentPrompt;
use Throwable;

/**
 * Una línea `ai.agent.called` por invocación de un agente del SDK (evaluación,
 * media, copiloto): agente, proveedor, modelo, tokens, pasos, herramientas y
 * latencia. Nunca el prompt, los adjuntos ni la respuesta. La medición de
 * uso (cobro) es aparte: la hace quien llama al agente.
 */
class AgentCallLogListener
{
    /**
     * Tope de invocaciones abiertas: si un proveedor nunca responde ni falla,
     * el worker de larga vida no acumula inicios para siempre.
     */
    private const int MAX_OPEN_INVOCATIONS = 500;

    /**
     * @var array<string, int> invocationId → hrtime de inicio
     */
    private static array $startedAt = [];

    public function started(PromptingAgent $event): void
    {
        if (count(self::$startedAt) >= self::MAX_OPEN_INVOCATIONS) {
            self::$startedAt = [];
        }

        self::$startedAt[$event->invocationId] = (int) hrtime(true);
    }

    public function completed(AgentPrompted $event): void
    {
        SystemLog::ok('ai.agent.called', input: self::input($event->invocationId, $event->prompt) + [
            'streamed' => $event instanceof AgentStreamed,
        ], result: self::result($event), durationMs: self::elapsed($event->invocationId));
    }

    public function failed(AgentFailed $event): void
    {
        SystemLog::degraded('ai.agent.called', reason: 'agent_error', input: self::input($event->invocationId, $event->prompt), calc: [
            'final_attempt' => self::finalAttempt($event->prompt),
        ], error: $event->exception, durationMs: self::elapsed($event->invocationId));
    }

    /**
     * @return array<string, mixed>
     */
    private static function input(string $invocationId, AgentPrompt $prompt): array
    {
        // Loguear nunca rompe al agente: un prompt a medio construir (dobles
        // de test, un SDK que cambia) deja la línea sin esos campos.
        try {
            return [
                'agent' => class_basename($prompt->agent),
                'invocation_id' => $invocationId,
                'parent_invocation_id' => $prompt->parentInvocationId,
                'attachments_count' => $prompt->attachments->count(),
            ];
        } catch (Throwable) {
            return ['agent' => null, 'invocation_id' => $invocationId];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function result(AgentPrompted $event): array
    {
        try {
            $response = $event->response;
            $usage = $response->usage;

            return [
                'provider' => $response->meta->provider,
                'model' => $response->meta->model,
                'input_tokens' => $usage->inputTokens,
                'output_tokens' => $usage->outputTokens,
                'cache_read_input_tokens' => $usage->cacheReadInputTokens,
                'reasoning_tokens' => $usage->reasoningTokens,
                'steps' => $response->steps->count(),
                'tool_calls' => $response->toolCalls->count(),
            ];
        } catch (Throwable) {
            return [];
        }
    }

    private static function finalAttempt(AgentPrompt $prompt): ?bool
    {
        try {
            return $prompt->isFinalAttempt();
        } catch (Throwable) {
            return null;
        }
    }

    private static function elapsed(string $invocationId): ?int
    {
        $started = self::$startedAt[$invocationId] ?? null;
        unset(self::$startedAt[$invocationId]);

        return $started === null ? null : SystemLog::elapsedMs($started);
    }
}
