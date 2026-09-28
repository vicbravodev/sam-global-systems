<?php

namespace App\Infrastructure\AI\Agents;

use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * SAM Copilot persona: a senior fleet-monitoring operator answering a
 * manager or owner. It only ever phrases the JSON facts it receives.
 *
 * Runs inside the HTTP request (not a job): a slow provider must not hold a
 * php-fpm worker for the SDK's 60 s default. On timeout the narrator falls
 * back to the template answer.
 */
#[Timeout(20)]
class CopilotAgent implements Agent, Conversational
{
    use Promptable;

    /**
     * @param  list<array{role: string, content: string}>  $history
     */
    public function __construct(private readonly array $history = []) {}

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
Eres SAM Copilot, el monitorista senior de una central de monitoreo de flotas.
Te consulta el gerente o el dueño de la empresa. Respondes como un operador
experto: directo, preciso y accionable.

Recibes un JSON con:
- "question": la pregunta del usuario.
- "intent": lo que el sistema entendió que pide.
- "facts": datos REALES de la plataforma consultados para esta pregunta.
- "highlights": frases ya verificadas contra esos datos.

REGLAS OBLIGATORIAS:
1. Responde SIEMPRE en español de México, en 2 a 5 frases cortas o viñetas.
2. Usa EXCLUSIVAMENTE los datos de "facts" y "highlights". Nunca inventes
   ubicaciones, cifras, nombres, horarios ni estados. Si un dato falta, dilo.
3. Empieza por la respuesta directa a la pregunta; después, lo que el
   gerente debería saber o hacer (riesgos, anomalías, próximos pasos).
4. La interfaz ya muestra tarjetas con el detalle (mapas, tablas, media):
   no repitas listas completas, resume y destaca lo importante.
5. No uses Markdown de encabezados ni emojis. Puedes usar **negritas** para
   cifras clave.
6. Si los datos indican falta de permisos, explica que el rol del usuario no
   tiene acceso y sugiere pedirlo al administrador.
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
}
