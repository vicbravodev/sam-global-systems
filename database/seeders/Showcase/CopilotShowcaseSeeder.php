<?php

namespace Database\Seeders\Showcase;

use App\Domains\Copilot\Models\CopilotConversation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Showcase\Support\ShowcaseRandom;
use Illuminate\Support\Facades\DB;

/**
 * Historial de SAM Copilot: conversaciones de los usuarios demo repartidas
 * en toda la ventana (para que las vistas de uso a 7/30/90 días tengan
 * serie), con pares pregunta/respuesta, intención, canal (página o
 * burbuja), modelo, tokens, costo, latencia y calificaciones 👍/👎.
 *
 * Idempotencia: un usuario que ya tiene conversaciones no recibe más.
 */
class CopilotShowcaseSeeder extends ShowcaseStep
{
    /**
     * intención => [pregunta, respuesta, bloque kpi]
     *
     * @var array<string, array<int, array{0: string, 1: string}>>
     */
    private const DIALOGUES = [
        'fleet_overview' => [
            ['¿Cómo está la flota ahora?', 'Tienes %d unidades: la mayoría en ruta, algunas detenidas en base y un par sin señal desde hace más de 3 horas. Revisa las que están en alerta primero.'],
            ['¿Cuántas unidades están sin señal?', 'Hay unidades sin reportar posición en las últimas horas; casi todas están estacionadas en la base de Apodaca.'],
        ],
        'open_incidents' => [
            ['¿Qué incidentes siguen abiertos?', 'Hay incidentes abiertos; los críticos son pánicos pendientes de verificación telefónica. Te dejo la lista ordenada por SLA.'],
            ['¿Algún incidente crítico sin atender?', 'Sí: un pánico sin reconocer supera ya su SLA de 5 minutos. Te sugiero tomarlo ahora.'],
        ],
        'panic_kpis' => [
            ['¿Cuántos pánicos tuvimos esta semana y cuántos fueron falsos?', 'Esta semana hubo varios botones de pánico; cerca de un tercio fueron activaciones accidentales confirmadas por llamada.'],
        ],
        'driver_ranking' => [
            ['¿Quiénes son los conductores con más riesgo?', 'Los tres conductores con mayor riesgo concentran frenados bruscos y excesos de velocidad en la autopista a Saltillo.'],
        ],
        'asset_location' => [
            ['¿Dónde está la unidad T-105?', 'La unidad reportó su última posición hace pocos minutos en Carretera Miguel Alemán, Apodaca, a 62 km/h.'],
        ],
        'fuel_report' => [
            ['¿Qué unidades tienen poco combustible?', 'Cuatro unidades están por debajo del 25 % de combustible; dos de ellas salen a ruta en la próxima hora.'],
        ],
        'engine_stats' => [
            ['¿Hay unidades con temperatura de motor alta?', 'Ninguna unidad supera los 100 °C; la más caliente reporta 96 °C en ralentí.'],
        ],
        'asset_media' => [
            ['Muéstrame las fotos del último pánico', 'Te dejo las capturas de cabina y camino del último pánico; la IA no detectó riesgo en cabina.'],
        ],
        'general' => [
            ['¿Cómo configuro el horario operativo?', 'En Configuración → Tenant puedes editar el horario por día y las reglas de turnos; los eventos fuera de horario se marcan automáticamente.'],
        ],
    ];

    private int $openIncidents = 0;

    public function run(): void
    {
        $this->openIncidents = DB::table('incidents')->where('team_id', $this->ctx->team->id)->whereNull('resolved_at')->whereNull('deleted_at')->count();
        $roles = $this->ctx->light ? ['admin'] : ['admin', 'supervisor', 'monitor', 'analyst'];

        foreach ($roles as $role) {
            $user = $this->ctx->users[$role] ?? null;

            if ($user === null || CopilotConversation::query()->where('team_id', $this->ctx->team->id)->where('user_id', $user->id)->exists()) {
                continue;
            }

            $this->seedUser($user, $role);
        }
    }

    private function seedUser(User $user, string $role): void
    {
        $random = ShowcaseRandom::forKey($this->ctx->key('copilot', (string) $user->id));
        $conversations = max(2, (int) round($this->ctx->days / ($role === 'analyst' ? 10 : 5)));
        $messages = [];

        for ($c = 0; $c < $conversations; $c++) {
            // Más uso reciente: la adopción crece con el tiempo.
            $daysAgo = (int) floor(($this->ctx->days - 1) * ($random->float(0, 1, 4) ** 1.8));
            $started = $random->operationalMoment($this->ctx->now->subDays($daysAgo)->startOfDay());

            if ($started->greaterThan($this->ctx->now)) {
                $started = $this->ctx->now->subMinutes($random->int(5, 90));
            }

            $intent = $random->weighted(['fleet_overview' => 25, 'open_incidents' => 22, 'panic_kpis' => 12, 'driver_ranking' => 10, 'asset_location' => 12, 'fuel_report' => 6, 'engine_stats' => 4, 'asset_media' => 5, 'general' => 4]);
            $turns = $random->int(1, 4);
            $channel = $random->chance(0.35) ? 'bubble' : 'page';
            [$firstQuestion] = $random->pick(self::DIALOGUES[$intent]);

            $conversation = CopilotConversation::query()->create([
                'team_id' => $this->ctx->team->id,
                'user_id' => $user->id,
                'title' => mb_strimwidth($firstQuestion, 0, 60, '…'),
                'is_pinned' => $c === 0 && $random->chance(0.5),
                'messages_count' => $turns * 2,
                'last_message_at' => $started->addMinutes($turns * 2),
                'created_at' => $started,
                'updated_at' => $started->addMinutes($turns * 2),
            ]);
            $this->ctx->count('copilot_conversations');

            for ($t = 0; $t < $turns; $t++) {
                $turnIntent = $t === 0 ? $intent : $random->weighted(['fleet_overview' => 3, 'open_incidents' => 3, 'asset_location' => 3, 'driver_ranking' => 2, 'general' => 1]);
                [$question, $answer] = $t === 0 ? $random->pick(self::DIALOGUES[$intent]) : $random->pick(self::DIALOGUES[$turnIntent]);
                $askedAt = $started->addMinutes($t * 2);
                $in = $random->int(900, 3_400);
                $out = $random->int(120, 620);

                $messages[] = $this->message($conversation->id, $user->id, 'user', $question, null, $channel, $askedAt);
                $messages[] = $this->message($conversation->id, null, 'assistant', sprintf($answer, $this->ctx->assets->count()), $turnIntent, $channel, $askedAt->addSeconds($random->int(2, 9)), [
                    'model' => $random->chance(0.9) ? 'gpt-5.4-mini' : 'template',
                    'input_tokens' => $in,
                    'output_tokens' => $out,
                    'cost_estimate' => round($in * 0.00000025 + $out * 0.000002, 6),
                    'latency_ms' => $random->int(700, 5_200),
                    'feedback' => match (true) {
                        $random->chance(0.55) => null,
                        $random->chance(0.8) => 1,
                        default => -1,
                    },
                ]);
            }
        }

        $this->bulkInsert('copilot_messages', $messages, timestamps: false);
    }

    /**
     * @param  array<string, mixed>  $usage
     * @return array<string, mixed>
     */
    private function message(int $conversationId, ?int $userId, string $role, string $content, ?string $intent, string $channel, CarbonImmutable $at, array $usage = []): array
    {
        return [
            'team_id' => $this->ctx->team->id,
            'copilot_conversation_id' => $conversationId,
            'user_id' => $userId,
            'role' => $role,
            'content' => $content,
            'intent' => $intent,
            'channel' => $channel,
            'context_json' => $role === 'assistant' ? ['showcase' => true] : null,
            'blocks_json' => $role === 'assistant' ? [[
                'type' => 'kpis',
                'items' => [
                    ['label' => 'Unidades', 'value' => $this->ctx->assets->count()],
                    ['label' => 'Incidentes abiertos', 'value' => $this->openIncidents],
                ],
            ]] : null,
            'tools_json' => $role === 'assistant' && $intent !== 'general' ? [['tool' => (string) $intent, 'label' => 'Consulta de '.str_replace('_', ' ', (string) $intent)]] : null,
            'sources_json' => null,
            'model' => $usage['model'] ?? null,
            'input_tokens' => $usage['input_tokens'] ?? 0,
            'output_tokens' => $usage['output_tokens'] ?? 0,
            'cost_estimate' => $usage['cost_estimate'] ?? 0,
            'latency_ms' => $usage['latency_ms'] ?? 0,
            'feedback' => $usage['feedback'] ?? null,
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }
}
