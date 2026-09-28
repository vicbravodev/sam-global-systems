<?php

namespace Database\Seeders\Showcase\Support;

use App\Contracts\AI\EventEvaluationAgent;
use App\Contracts\AI\MediaAssessmentAgent;
use App\Contracts\NullImplementations\NullEventEvaluationAgent;
use App\Contracts\NullImplementations\NullMediaAssessmentAgent;
use App\Domains\Notifications\Channels\TwilioClientFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use RuntimeException;

/**
 * Neutraliza TODO efecto externo mientras corre el showcase. Sembrar nunca
 * debe mandar un SMS/WhatsApp/llamada/email ni tocar APIs de terceros, y
 * tampoco puede dejar trabajo en la cola real: en dev Horizon está vivo
 * contra Valkey y procesaría cualquier job con drivers reales.
 *
 * Cómo:
 *  - `Queue::fake()`: todo job y listener encolado queda capturado en memoria
 *    (incluidos WriteAuditLogJob, broadcasts encolados y entregas de
 *    notificación). El replay drena a mano sólo las colas del pipeline
 *    ({@see SyncPipelineRunner}); el resto se descarta al terminar.
 *  - `Mail::fake()` + `Notification::fake()`: ningún correo ni notificación
 *    Laravel sale del proceso.
 *  - `Http::preventStrayRequests()`: cualquier llamada HTTP (Samsara, OpenAI,
 *    Twilio vía Http, webhooks de automatización) lanza excepción en vez de
 *    salir a la red.
 *  - `TwilioClientFactory` resuelve a una excepción: el SDK de Twilio no usa el
 *    cliente Http de Laravel, así que se corta en su factoría.
 *  - Agentes de IA → implementaciones Null (evaluación por reglas), y
 *    broadcasting → driver `null`.
 */
final class ShowcaseSandbox
{
    public static function enter(): void
    {
        config([
            'broadcasting.default' => 'null',
            'mail.default' => 'array',
        ]);

        if (! Queue::getFacadeRoot() instanceof QueueFake) {
            Queue::fake();
        }

        Mail::fake();
        Notification::fake();
        Http::preventStrayRequests();

        app()->bind(TwilioClientFactory::class, function (): never {
            throw new RuntimeException('Showcase sandbox: Twilio está deshabilitado mientras se siembran datos.');
        });

        app()->instance(EventEvaluationAgent::class, app(NullEventEvaluationAgent::class));
        app()->instance(MediaAssessmentAgent::class, app(NullMediaAssessmentAgent::class));
    }
}
