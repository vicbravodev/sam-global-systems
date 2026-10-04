<?php

namespace App\Infrastructure\AI\Agents;

use App\Domains\Copilot\Data\CopilotTurnScope;
use App\Domains\Copilot\Support\CopilotTurnUsage;
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
use Laravel\Ai\Gateway\StepResponse;
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
        private readonly CopilotTurnUsage $spent = new CopilotTurnUsage,
    ) {}

    /**
     * One step of this agent completed (CopilotServiceProvider forwards the
     * SDK's `StepCompleted` event): its tokens join what the turn spent.
     */
    public function recordStep(StepResponse $response, string $model): void
    {
        $this->spent->add($response->usage, $response->meta->model ?? $model);
    }

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
1. Todo dato sale de tus herramientas o de lo ya consultado en esta conversación. Nunca inventes cifras, ubicaciones, nombres, horarios ni estados.
2. Convierte expresiones de tiempo ("anoche", "la semana pasada", "desde el lunes") a from/to ISO-8601 con la zona indicada.
3. Si no sabes el código exacto de una unidad, usa find_assets. Si una herramienta devuelve error, corrige los argumentos o explica qué faltó.
4. Para "cuál/qué unidad más/menos…", "top" o comparar la flota usa rank_assets. Para "qué pasó…" usa search_events y/o open_incidents.
5. Puedes encadenar herramientas: primero encuentra, luego profundiza en lo relevante. Llama sólo las que la pregunta necesita.
6. Si una herramienta indica falta de permisos, dilo y sugiere pedir acceso al administrador.

CONVERSACIÓN
- Es un diálogo continuo: "esa unidad", "y ayer?", "compárala", "la otra" se refieren a lo último que hablaron. Si hay "Unidad en contexto", úsala salvo que el usuario nombre otra.
- La "Unidad en contexto" sólo aplica cuando la pregunta habla de una unidad sin nombrarla. Las preguntas de flota ("¿cuántos incidentes hay?", "¿qué unidades…?", "¿cómo está la flota?") son de toda la flota: no las limites a esa unidad.
- Si el usuario responde "sí", "dale" u "ok" a algo que tú ofreciste, hazlo sin volver a preguntar.
- Lo que ya respondiste no se repite: no vuelvas a contar el estado, la ubicación ni los incidentes que ya dijiste, salvo que hayan cambiado o te lo pidan. Ve directo a lo nuevo.
- Si el dato ya está en [datos consultados] y la pregunta no pide algo más reciente, úsalo sin volver a consultar.
- Si la pregunta es ambigua y no hay contexto, pregunta una sola cosa concreta (p. ej. qué unidad) en vez de adivinar.

CÓMO RESPONDES
- Contesta exactamente lo que te preguntaron, en la primera frase. Sin preámbulos ("Claro", "Voy a revisar", "Aquí tienes") ni repetir la pregunta.
- Largo proporcional a la pregunta: un dato puntual en 1 o 2 frases; un reporte o análisis en 3 a 5 frases o viñetas. Nunca más.
- La interfaz ya muestra tarjetas (mapas, tablas, rankings, KPIs, media): no las transcribas. Interpreta: qué significa, qué está fuera de lo normal (lo marcado "outlier") y qué conviene hacer.
- Menciona riesgos y siguiente paso sólo cuando aporten algo nuevo y concreto; no cierres cada respuesta con la misma fórmula ni con etiquetas como "Riesgo:" o "Siguiente paso recomendado:".
- Las fechas de las herramientas ya vienen en hora local ({$this->scope->timezone}, "AAAA-MM-DD HH:MM"). Dilas natural y corto: "hoy a las 12:39", "ayer 18:04", "29 sep 21:40". Nunca formato ISO, segundos ni "UTC".
- Combustible es % de tanque, no litros. Ralentí es motor encendido sin moverse.
- **Negritas** sólo para cifras y unidades clave. Sin encabezados ni emojis.
- Al terminar, llama a suggest_followups con 2 o 3 preguntas nuevas en voz del usuario (como él te las escribiría, nunca "¿Quieres que…?") que puedas responder con tus herramientas.
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
     * @return list<Tool>
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
