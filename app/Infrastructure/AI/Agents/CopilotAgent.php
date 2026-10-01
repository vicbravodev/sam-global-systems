<?php

namespace App\Infrastructure\AI\Agents;

use App\Domains\Copilot\Data\CopilotTurnScope;
use App\Infrastructure\AI\Middleware\CopilotStepGuard;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Attributes\CacheToolDefinitions;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * SAM Copilot: a senior fleet-monitoring operator answering a manager or
 * owner. A multi-step tool-calling agent: every fact comes from the
 * permission-filtered, tenant-scoped tools of the turn, and CopilotStepGuard
 * caps steps and tokens.
 *
 * Runs inside the HTTP request (not a job): the timeout keeps a slow provider
 * from holding a php-fpm worker for the SDK's 60 s default.
 */
#[MaxSteps(6)]
#[Timeout(45)]
#[CacheInstructions]
#[CacheToolDefinitions]
class CopilotAgent implements Agent, Conversational, HasMiddleware, HasTools
{
    use Promptable;

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @param  list<Tool>  $tools
     */
    public function __construct(
        private readonly CopilotTurnScope $scope,
        private readonly array $history,
        private readonly array $tools,
        private readonly CopilotStepGuard $guard,
    ) {}

    public function instructions(): Stringable|string
    {
        $now = $this->scope->now->setTimezone($this->scope->timezone);
        $readable = $now->settings(['locale' => 'es_MX'])->translatedFormat('l j \d\e F \d\e Y, H:i');
        $iso = $now->toIso8601String();

        return <<<INSTRUCTIONS
Eres SAM Copilot, el monitorista senior de una central de monitoreo de flotas.
Te consulta el gerente o el dueño. Respondes SIEMPRE en español de México: directo, preciso y accionable.

Ahora es {$readable} (zona {$this->scope->timezone}, ISO {$iso}).

CÓMO TRABAJAS
1. Todo dato sale de tus herramientas. Nunca inventes cifras, ubicaciones, nombres, horarios ni estados.
2. Convierte expresiones de tiempo ("anoche", "la semana pasada", "desde el lunes") a from/to ISO-8601 con la zona indicada.
3. Si no sabes el código exacto de una unidad, usa find_assets. Si una herramienta devuelve error, corrige los argumentos o explica qué faltó.
4. Para "cuál/qué unidad más/menos…", "top" o comparar la flota usa rank_assets. Para "qué pasó…" usa search_events y/o open_incidents.
5. Puedes encadenar herramientas: primero encuentra, luego profundiza en lo relevante.
6. Si una herramienta indica falta de permisos, dilo y sugiere pedir acceso al administrador.

CÓMO RESPONDES
- Primero la respuesta directa; luego riesgos, anomalías (marcadas "outlier") y el siguiente paso recomendado.
- 2 a 6 frases o viñetas. La interfaz ya muestra tarjetas (mapas, tablas, rankings): no repitas listas, destaca lo importante.
- Combustible es % de tanque, no litros. Ralentí es motor encendido sin moverse.
- **Negritas** sólo para cifras y unidades clave. Sin encabezados ni emojis.
- Al terminar, llama a suggest_followups con 2 o 3 preguntas de seguimiento.
INSTRUCTIONS;
    }

    /**
     * @return iterable<Message>
     */
    public function messages(): iterable
    {
        return array_map(
            fn (array $turn) => new Message($turn['role'], $turn['content']),
            $this->history,
        );
    }

    /**
     * @return iterable<Tool>
     */
    public function tools(): iterable
    {
        return $this->tools;
    }

    /**
     * @return array<int, mixed>
     */
    public function middleware(): array
    {
        return [$this->guard];
    }
}
