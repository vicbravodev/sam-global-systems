<?php

namespace App\Domains\Copilot\Tools\Sdk;

use App\Domains\Copilot\Support\CopilotFollowups;
use App\Domains\Copilot\Support\CopilotTurnCollector;
use App\Support\SystemLog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Lets the model propose the follow-up questions the UI shows as chips. A
 * chip is sent as the user's own message, so each one must be written in
 * the user's voice; offers of the assistant, repeats and questions already
 * asked are dropped (CopilotFollowups). It reads no data, so it needs no
 * permission and is always offered.
 */
final class SuggestFollowupsTool implements Tool
{
    public function __construct(
        private readonly CopilotTurnCollector $collector,
        private readonly ?int $teamId = null,
    ) {}

    public function name(): string
    {
        return 'suggest_followups';
    }

    public function description(): string
    {
        return 'Llama esto al final con 2 o 3 preguntas cortas que el gerente probablemente haría después. '
            .'Se envían tal cual como mensaje DEL USUARIO al tocarlas, así que escríbelas en su voz, como él te las preguntaría: '
            .'"¿Dónde está la T-77 ahora?", "Muéstrame los pánicos de ayer", "Compara la T-0524 con el resto de la flota". '
            .'NUNCA como ofrecimiento tuyo ("¿Quieres que…?", "¿Te muestro…?", "¿Reviso…?"). '
            .'Que cada una aporte algo nuevo y puedas responderla con tus herramientas: no repitas preguntas ya hechas en la conversación ni lo que ya respondiste. '
            .'Usa el código de la unidad en lugar de "esta unidad".';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'questions' => $schema->array()
                ->items($schema->string()->max(CopilotFollowups::MAX_CHARS))
                ->min(2)
                ->max(CopilotFollowups::MAX)
                ->description('Preguntas en voz del usuario, p. ej. "¿Qué unidad tuvo más excesos de velocidad hoy?"')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $dropped = $this->collector->followups(array_values((array) ($request['questions'] ?? [])));
        $kept = count($this->collector->followupList());

        if ($dropped > 0) {
            SystemLog::skipped('copilot.followups.filtered', 'not_user_voice_or_repeated', ['team_id' => $this->teamId], calc: ['kept' => $kept, 'dropped' => $dropped], debug: true);
        }

        return $kept === 0 && $dropped > 0
            ? 'descartadas: escríbelas como las preguntaría el usuario, no como ofrecimiento tuyo, y sin repetir lo ya preguntado'
            : 'ok';
    }
}
