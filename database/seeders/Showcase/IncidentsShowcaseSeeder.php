<?php

namespace Database\Seeders\Showcase;

use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Incidents\Models\IncidentStatus;
use App\Domains\Incidents\Models\IncidentType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Showcase\Support\EventScenario;
use Database\Seeders\Showcase\Support\ShowcaseEvents;
use Database\Seeders\Showcase\Support\ShowcaseRandom;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Incidentes con ciclo de vida completo para los eventos cuyo escenario lo
 * pide: los que la IA+motor mandaron a INCIDENT/ESCALATE/REVIEW y algunos
 * que un monitorista abre a mano (fatiga, casi-colisión). Cada uno trae
 * timeline, asignación, reconocimiento y toma, comentarios, evidencia, SLA
 * (con ~12 % de incumplimientos), resolución, llamadas de verificación en
 * los pánicos y vínculos con incidentes previos de la misma unidad.
 *
 * El estado depende de la antigüedad: lo de las últimas horas sigue vivo
 * (nuevo / en revisión / escalado), lo viejo está resuelto, cerrado o
 * descartado, con un pequeño rezago que nunca se cerró.
 *
 * Marcador: `incidents.metadata_json.showcase_key`. Idempotencia: sólo
 * eventos SIN incidente (así los incidentes reales nunca se tocan).
 */
class IncidentsShowcaseSeeder extends ShowcaseStep
{
    /** @var array<string, int> */
    private array $statuses = [];

    /** @var array<string, int> */
    private array $priorities = [];

    /** @var array<string, int> */
    private array $types = [];

    private ?int $voiceChannelId = null;

    /** @var array<int, array<int, array{id: int, opened: CarbonImmutable}>> asset_id => incidentes ya creados */
    private array $byAsset = [];

    public function run(): void
    {
        $this->statuses = IncidentStatus::query()->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();
        $this->priorities = IncidentPriority::query()->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();
        $this->types = IncidentType::query()->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();
        $this->voiceChannelId = NotificationChannel::query()->where('code', 'sam_voice')->value('id')
            ?? NotificationChannel::query()->where('channel_type', 'voice')->orderBy('id')->value('id');

        $events = new ShowcaseEvents($this->ctx);
        $query = $events->query()
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('incidents')->whereColumn('incidents.related_event_id', 'normalized_events.id'))
            ->whereHas('eventType', fn ($q) => $q->whereIn('code', ['panic_button', 'collision', 'tampering', 'camera_obstructed', 'hos_violation', 'after_hours_movement', 'suspicious_stop', 'geofence_exit', 'driver_fatigue', 'near_collision']))
            ->orderBy('occurred_at');

        $events->chunk($query, function (Collection $chunk) use ($events) {
            $decisions = DB::table('decisions')->whereIn('normalized_event_id', $chunk->pluck('id'))->get()->keyBy('normalized_event_id');
            $evaluations = DB::table('ai_event_evaluations')->whereIn('normalized_event_id', $chunk->pluck('id'))->orderBy('evaluation_version')->get()->keyBy('normalized_event_id');
            $media = DB::table('event_media_contexts')->whereIn('normalized_event_id', $chunk->pluck('id'))->get()->groupBy('normalized_event_id');
            $rows = ['incident_timelines' => [], 'incident_assignments' => [], 'incident_comments' => [], 'incident_evidence' => [], 'incident_resolutions' => [], 'incident_event_links' => [], 'incident_call_verifications' => [], 'event_related_incident_links' => []];

            foreach ($chunk as $event) {
                $s = $events->scenario($event);

                if (! $s->opensIncident()) {
                    continue;
                }

                $decision = $decisions->get($event->id);

                // Un evento que pasa por IA sólo abre incidente si el motor decidió.
                if ($s->evaluate && $decision === null) {
                    continue;
                }

                $this->createIncident($event, $s, $decision, $evaluations->get($event->id), $media->get($event->id, collect()), $rows);
            }

            foreach ($rows as $table => $tableRows) {
                $this->bulkInsert($table, $tableRows, timestamps: false);
            }
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<int, stdClass>  $media
     * @param  array<string, array<int, array<string, mixed>>>  $rows
     */
    private function createIncident(NormalizedEvent $event, EventScenario $s, ?stdClass $decision, ?stdClass $evaluation, $media, array &$rows): void
    {
        $random = ShowcaseRandom::forKey('incident:'.$event->id);
        $manual = $decision === null;
        $occurred = CarbonImmutable::parse($event->occurred_at);
        $opened = $manual
            ? $occurred->addMinutes($random->int(4, 35))
            : CarbonImmutable::parse($decision->decided_at)->addSeconds($random->int(1, 3));

        if ($opened->greaterThan($this->ctx->now)) {
            $opened = $this->ctx->now->subSeconds(30);
        }

        $priority = (string) $s->incidentPriority;
        $life = $this->lifecycle($opened, $priority, $s, $random);
        $operator = $this->operatorFor($opened);
        $supervisor = $this->ctx->users['supervisor'] ?? $this->ctx->user('admin');
        $assetLabel = $event->asset?->name ?? 'unidad sin identificar';

        $incident = Incident::query()->create([
            'team_id' => $this->ctx->team->id,
            'incident_type_id' => $this->types[$s->incidentType] ?? $this->types['other'],
            'incident_status_id' => $this->statuses[$life['status']],
            'incident_priority_id' => $this->priorities[$priority] ?? $this->priorities['medium'],
            'source_type' => $manual ? 'manual' : 'ai_decision',
            'source_reference_id' => $decision?->id,
            'related_event_id' => $event->id,
            'related_decision_id' => $decision?->id,
            'asset_id' => $event->asset_id,
            'driver_id' => $event->driver_id,
            'title' => ($event->eventType?->name ?? 'Incidente').' — '.$assetLabel,
            'summary' => $evaluation?->explanation_text ?? $this->manualSummary($s, $assetLabel),
            'description' => $manual ? 'Abierto por el monitorista al revisar el evento de seguridad en la consola.' : null,
            'opened_at' => $opened,
            'sla_due_at' => $life['sla_due_at'],
            'acknowledged_at' => $life['acknowledged_at'],
            'acknowledged_by' => $life['acknowledged_at'] ? $operator->id : null,
            'claimed_by_user_id' => $life['acknowledged_at'] && ! $life['terminal'] ? $operator->id : null,
            'claimed_at' => $life['acknowledged_at'] && ! $life['terminal'] ? $life['acknowledged_at']->addSeconds(20) : null,
            'resolved_at' => $life['resolved_at'],
            'closed_at' => $life['closed_at'],
            'false_positive_at' => $life['status'] === 'false_positive' ? $life['resolved_at'] : null,
            'cancelled_at' => $life['status'] === 'cancelled' ? $life['resolved_at'] : null,
            'external_resolved_at' => $life['external_resolved_at'],
            'created_by_type' => $manual ? 'user' : 'system',
            'created_by_id' => $manual ? $operator->id : null,
            'metadata_json' => ['showcase' => true, 'showcase_key' => $this->ctx->key('incident', (string) $event->id)],
            'created_at' => $opened,
            'updated_at' => $life['resolved_at'] ?? $life['acknowledged_at'] ?? $opened,
        ]);
        $this->ctx->count('incidents');

        $timeline = function (string $type, string $actorType, ?int $actorId, string $title, ?string $description, CarbonImmutable $at, ?array $payload = null) use (&$rows, $incident): void {
            if ($at->greaterThan($this->ctx->now)) {
                return;
            }

            $rows['incident_timelines'][] = [
                'incident_id' => $incident->id,
                'entry_type' => $type,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'title' => $title,
                'description' => $description,
                'payload_json' => $payload,
                'occurred_at' => $at,
                'created_at' => $at,
                'updated_at' => $at,
            ];
        };

        $timeline('created', $manual ? 'user' : 'ai', $manual ? $operator->id : null, 'Incidente creado', $manual ? 'Creado manualmente desde la consola.' : "Decisión {$s->decisionCode} del motor sobre el evento.", $opened);
        $timeline('event_linked', 'system', null, 'Evento vinculado', $event->eventType?->name, $opened, ['normalized_event_id' => $event->id]);
        $rows['incident_event_links'][] = ['incident_id' => $incident->id, 'normalized_event_id' => $event->id, 'relation_type' => 'root_trigger', 'created_at' => $opened, 'updated_at' => $opened];

        $assignedAt = $opened->addSeconds($random->int(5, 40));

        if ($assignedAt->lessThan($this->ctx->now)) {
            $rows['incident_assignments'][] = [
                'incident_id' => $incident->id,
                'assigned_to_type' => 'user',
                'assigned_to_id' => $operator->id,
                'role' => 'primary',
                'assigned_at' => $assignedAt,
                'unassigned_at' => $life['status'] === 'escalated' ? $life['escalated_at'] : null,
                'assigned_by_type' => 'automation',
                'assigned_by_id' => null,
                'metadata_json' => ['rule' => 'on_call_rotation'],
                'created_at' => $assignedAt,
                'updated_at' => $assignedAt,
            ];
            $timeline('assigned', 'automation', null, 'Asignado', "Asignado a {$operator->name} por guardia.", $assignedAt, ['user_id' => $operator->id]);
        }

        if ($s->withMedia && $media->isNotEmpty()) {
            $snapshot = $media->firstWhere('media_type', 'snapshot');
            $timeline('media_assessed', 'ai', null, 'Evidencia visual evaluada', 'La IA revisó las fotos de cabina y camino.', $opened->addMinutes(3), ['result' => $s->isReal() ? 'confirms_event' : 'contradicts_event', 'confidence_score' => $s->confidence]);

            if ($snapshot !== null) {
                $rows['incident_evidence'][] = $this->evidence($incident->id, 'image', 'event_media', (int) $snapshot->id, 'Foto de cámara frontal', 'Captura al momento del evento.', '/showcase/road-camera.svg', $opened->addMinutes(3));
            }

            $rows['incident_evidence'][] = $this->evidence($incident->id, 'video', 'event_media', null, 'Clip de 20 s solicitado', 'Solicitado a la cámara; puede expirar si la SD se sobrescribe.', null, $opened->addMinutes(1));
        }

        $rows['incident_evidence'][] = $this->evidence($incident->id, 'event_snapshot', 'normalized_event', $event->id, 'Evento original', 'Payload normalizado del proveedor.', null, $opened);

        if ($evaluation !== null) {
            $rows['incident_evidence'][] = $this->evidence($incident->id, 'ai_explanation', 'ai_evaluation', (int) $evaluation->id, 'Explicación de la IA', $evaluation->explanation_text, null, $opened);
        }

        if ($s->typeCode === 'panic_button') {
            $this->verificationCalls($incident->id, $event, $s, $opened, $random, $rows, $timeline);
        }

        if ($life['acknowledged_at'] !== null) {
            $timeline('acknowledged', 'user', $operator->id, 'Reconocido', "{$operator->name} tomó conocimiento.", $life['acknowledged_at']);
            $timeline('claimed', 'user', $operator->id, 'Tomado', "{$operator->name} está atendiendo el caso.", $life['acknowledged_at']->addSeconds(20));
        }

        foreach ($this->comments($s, $life, $random) as $i => [$text, $visibility, $offset]) {
            $at = ($life['acknowledged_at'] ?? $opened)->addMinutes($offset);

            if ($at->greaterThan($life['resolved_at'] ?? $this->ctx->now)) {
                continue;
            }

            $author = $i === 1 ? $supervisor : $operator;
            $rows['incident_comments'][] = ['incident_id' => $incident->id, 'user_id' => $author->id, 'comment' => $text, 'visibility' => $visibility, 'created_at' => $at, 'updated_at' => $at];
            $timeline('comment_added', 'user', $author->id, 'Comentario', mb_substr($text, 0, 120), $at);
        }

        if ($life['escalated_at'] !== null) {
            $timeline('escalated', 'automation', null, 'Escalado', "Escalado a {$supervisor->name} (política de emergencia crítica).", $life['escalated_at']);
            $timeline('action_executed', 'automation', null, 'SMS y llamada al supervisor', 'Automatización «Protocolo de emergencia» ejecutada.', $life['escalated_at']->addSeconds(5));
            $rows['incident_assignments'][] = [
                'incident_id' => $incident->id,
                'assigned_to_type' => 'user',
                'assigned_to_id' => $supervisor->id,
                'role' => 'escalation',
                'assigned_at' => $life['escalated_at'],
                'unassigned_at' => null,
                'assigned_by_type' => 'automation',
                'assigned_by_id' => null,
                'metadata_json' => ['policy' => 'critical-emergency'],
                'created_at' => $life['escalated_at'],
                'updated_at' => $life['escalated_at'],
            ];
        }

        if ($life['sla_breached_at'] !== null) {
            $timeline('sla_breached', 'system', null, 'SLA incumplido', 'Se superó el tiempo de atención comprometido para la prioridad.', $life['sla_breached_at']);
        }

        if ($life['external_resolved_at'] !== null) {
            $timeline('externally_resolved', 'system', null, 'Resuelto en Samsara', 'La alerta se marcó como resuelta en el proveedor.', $life['external_resolved_at']);
        }

        if ($life['resolved_at'] !== null) {
            $closer = $life['status'] === 'cancelled' ? $supervisor : $operator;
            $timeline('resolved', 'user', $closer->id, 'Resuelto', $this->resolutionText($life['status'], $s), $life['resolved_at']);
            $rows['incident_resolutions'][] = $this->resolution($incident->id, $life, $s, $closer, $random);
            $this->recordVerdict($evaluation, $life, $closer);
        }

        if ($life['closed_at'] !== null) {
            $timeline('closed', 'user', $supervisor->id, 'Cerrado', 'Revisado y cerrado por supervisión.', $life['closed_at']);
        }

        $this->linkPriorIncidents($incident->id, $event, $opened, $rows);
    }

    /**
     * @return array{status: string, terminal: bool, sla_due_at: CarbonImmutable, acknowledged_at: ?CarbonImmutable, escalated_at: ?CarbonImmutable, resolved_at: ?CarbonImmutable, closed_at: ?CarbonImmutable, sla_breached_at: ?CarbonImmutable, external_resolved_at: ?CarbonImmutable}
     */
    private function lifecycle(CarbonImmutable $opened, string $priority, EventScenario $s, ShowcaseRandom $random): array
    {
        $slaSeconds = $this->ctx->slaSeconds[$priority] ?? 3_600;
        $slaDue = $opened->addSeconds($slaSeconds);
        [$ackMin, $ackMax, $fixMin, $fixMax] = match ($priority) {
            'critical' => [0.5, 4, 3, 5],
            'high' => [2, 15, 15, 28],
            'medium' => [5, 45, 30, 58],
            default => [10, 120, 120, 230],
        };
        // Resolución dentro del SLA salvo ~12 % de casos lentos.
        $slow = $random->chance(0.12);
        $resolveMinutes = match (true) {
            // Una emergencia escalada casi nunca se cierra en 5 min: incumple el SLA.
            $s->decisionCode === 'ESCALATE' => $random->float(18, 75),
            $slow => ($slaSeconds / 60) * $random->float(1.3, 3.0),
            default => $random->float($fixMin, $fixMax),
        };
        $ack = $opened->addSeconds((int) ($random->float($ackMin, $ackMax) * 60));
        $resolved = $opened->addSeconds((int) max($resolveMinutes * 60, $opened->diffInSeconds($ack) + 60));
        $stale = $random->chance(0.03);
        $escalates = $s->decisionCode === 'ESCALATE';

        $status = match (true) {
            ! $s->isReal() && $s->classification === 'false_positive' => 'false_positive',
            $random->chance(0.03) => 'cancelled',
            $random->chance(0.4) => 'closed',
            default => 'resolved',
        };

        $state = [
            'status' => $status,
            'terminal' => true,
            'sla_due_at' => $slaDue,
            'acknowledged_at' => $ack,
            'escalated_at' => $escalates ? $ack->addSeconds(30) : null,
            'resolved_at' => $resolved,
            'closed_at' => $status === 'closed' ? $resolved->addMinutes($random->int(30, 240)) : null,
            'sla_breached_at' => $resolved->greaterThan($slaDue) ? $slaDue : null,
            'external_resolved_at' => $s->typeCode === 'panic_button' && $random->chance(0.3) ? $resolved->subMinutes(1) : null,
        ];

        if ($stale || $resolved->greaterThan($this->ctx->now)) {
            $state['terminal'] = false;
            $state['resolved_at'] = null;
            $state['closed_at'] = null;
            $state['external_resolved_at'] = null;

            if ($ack->greaterThan($this->ctx->now)) {
                $state['acknowledged_at'] = null;
                $state['escalated_at'] = null;
                $state['status'] = 'open';
            } else {
                $state['status'] = $escalates ? 'escalated' : 'in_review';

                if ($state['escalated_at']?->greaterThan($this->ctx->now)) {
                    $state['escalated_at'] = null;
                    $state['status'] = 'in_review';
                }
            }

            $state['sla_breached_at'] = $slaDue->lessThan($this->ctx->now) ? $slaDue : null;
        } elseif ($state['closed_at']?->greaterThan($this->ctx->now)) {
            $state['closed_at'] = null;
            $state['status'] = 'resolved';
        }

        return $state;
    }

    private function operatorFor(CarbonImmutable $at): User
    {
        $hour = (int) $at->format('G');

        return match (true) {
            $hour >= 22 || $hour < 6 => $this->ctx->users['monitor_night'] ?? $this->ctx->user('monitor'),
            $hour >= 14 => $this->ctx->users['supervisor'] ?? $this->ctx->user('monitor'),
            default => $this->ctx->user('monitor'),
        };
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $rows
     */
    private function verificationCalls(int $incidentId, NormalizedEvent $event, EventScenario $s, CarbonImmutable $opened, ShowcaseRandom $random, array &$rows, \Closure $timeline): void
    {
        $phone = $event->driver?->phone
            ?? DB::table('driver_contacts')->where('driver_id', $event->driver_id)->where('contact_type', 'mobile_phone')->value('value')
            ?? '+12025550100';
        $attempts = $random->weighted(['1' => 60, '2' => 30, '3' => 10]);

        for ($attempt = 1; $attempt <= (int) $attempts; $attempt++) {
            $placed = $opened->addSeconds(20 + ($attempt - 1) * 90);

            if ($placed->greaterThan($this->ctx->now)) {
                break;
            }

            $last = $attempt === (int) $attempts;
            [$status, $outcome, $digits] = match (true) {
                ! $last => ['no_answer', null, null],
                $random->chance(0.1) => ['failed', null, null],
                $s->isReal() => ['answered', 'confirmed_real', '1'],
                $s->classification === 'false_positive' => ['answered', 'confirmed_false', '2'],
                default => ['no_answer', 'no_answer', null],
            };

            $rows['incident_call_verifications'][] = [
                'team_id' => $this->ctx->team->id,
                'incident_id' => $incidentId,
                'notification_channel_id' => $this->voiceChannelId,
                'phone' => $phone,
                'attempt' => $attempt,
                'status' => $status,
                'outcome' => $outcome,
                'digits_received' => $digits,
                'call_sid' => 'CA'.md5("showcase-call-{$incidentId}-{$attempt}"),
                'placed_at' => $placed,
                'responded_at' => $status === 'answered' ? $placed->addSeconds($random->int(8, 35)) : null,
                'metadata_json' => ['showcase' => true, 'error' => $status === 'failed' ? 'Twilio 13224: número no válido para llamadas.' : null],
                'created_at' => $placed,
                'updated_at' => $placed,
            ];

            $timeline('verification_call', 'system', null, 'Llamada de verificación', match ($status) {
                'answered' => $digits === '1' ? 'El conductor marcó 1: emergencia real.' : 'El conductor marcó 2: fue un error.',
                'failed' => 'La llamada no pudo completarse.',
                default => "Intento {$attempt}: sin respuesta.",
            }, $placed, ['attempt' => $attempt, 'status' => $status]);
        }
    }

    /**
     * @param  array<string, mixed>  $life
     * @return array<int, array{0: string, 1: string, 2: int}>
     */
    private function comments(EventScenario $s, array $life, ShowcaseRandom $random): array
    {
        $pool = match ($s->typeCode) {
            'panic_button' => $s->isReal()
                ? ['Conductor confirma asalto en curso, se contacta a 911 y al cliente.', 'Unidad localizada por GPS, patrulla en camino.', 'Conductor a salvo; carga íntegra. Se levanta reporte.']
                : ['Conductor indica que presionó el botón por accidente al acomodar la cabina.', 'Se verifica por cámara: cabina normal.', 'Se recuerda al operador el uso correcto del botón.'],
            'collision' => ['Se confirma alcance leve en caseta; sin lesionados.', 'Ajustador de seguro notificado.', 'Unidad sigue en ruta con daño menor en defensa.'],
            'camera_obstructed', 'tampering' => ['Cámara tapada con franela; se pide al operador retirarla.', 'Operador reincidente, se notifica a RH.', 'Cámara restablecida y verificada en vivo.'],
            'after_hours_movement', 'suspicious_stop' => ['Movimiento autorizado por el jefe de tráfico (entrega urgente).', 'Parada no programada en zona de riesgo; se contacta al operador.', 'Operador reporta revisión mecánica; se valida con taller.'],
            default => ['Se contacta al operador para retroalimentación.', 'Se programa sesión de coaching.', 'Caso documentado para el reporte semanal.'],
        };
        $count = $random->int(1, 3);
        $out = [];

        for ($i = 0; $i < $count; $i++) {
            $out[] = [$pool[$i % count($pool)], $i === 2 ? 'tenant_visible' : 'internal', 2 + $i * $random->int(3, 12)];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function evidence(int $incidentId, string $type, string $source, ?int $reference, string $title, ?string $description, ?string $fileUrl, CarbonImmutable $at): array
    {
        return [
            'incident_id' => $incidentId,
            'evidence_type' => $type,
            'source_type' => $source,
            'source_reference_id' => $reference,
            'title' => $title,
            'description' => $description,
            'file_url' => $fileUrl,
            'storage_path' => null,
            'metadata_json' => ['showcase' => true],
            'added_by_type' => 'system',
            'added_by_id' => null,
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }

    /**
     * @param  array<string, mixed>  $life
     * @return array<string, mixed>
     */
    private function resolution(int $incidentId, array $life, EventScenario $s, User $by, ShowcaseRandom $random): array
    {
        $code = match ($life['status']) {
            'false_positive' => 'false_positive',
            'cancelled' => 'duplicate_incident',
            default => $life['external_resolved_at'] !== null ? 'resolved_externally' : $random->weighted([
                'handled_successfully' => 60, 'operator_confirmed_safe' => 25, 'escalated_externally' => 10, 'unresolved_closed' => 5,
            ]),
        };

        return [
            'incident_id' => $incidentId,
            'resolution_code' => $code,
            'resolution_summary' => $this->resolutionText($life['status'], $s),
            'resolved_by_type' => 'user',
            'resolved_by_id' => $by->id,
            'root_cause' => $s->isReal() ? 'Evento operativo real en ruta.' : 'Activación accidental / falsa alarma del sensor.',
            'corrective_action' => $s->isReal() ? 'Atención coordinada con el operador y el cliente.' : 'Retroalimentación al operador sobre el uso del dispositivo.',
            'preventive_action' => $random->chance(0.5) ? 'Reforzar capacitación del operador en el siguiente turno.' : null,
            'resolved_at' => $life['resolved_at'],
            'metadata_json' => ['showcase' => true],
            'created_at' => $life['resolved_at'],
            'updated_at' => $life['resolved_at'],
        ];
    }

    private function resolutionText(string $status, EventScenario $s): string
    {
        return match ($status) {
            'false_positive' => 'Falsa alarma confirmada con el conductor y por cámara.',
            'cancelled' => 'Cancelado: duplicado de otro incidente abierto para la misma unidad.',
            default => $s->isReal() ? 'Situación atendida y controlada; el operador continúa en ruta.' : 'Revisado sin hallazgos de riesgo.',
        };
    }

    private function manualSummary(EventScenario $s, string $asset): string
    {
        return match ($s->typeCode) {
            'driver_fatigue' => "Fatiga recurrente del conductor de {$asset}: tercer evento del turno.",
            'near_collision' => "Casi colisión en {$asset}; se revisa el video con el operador.",
            default => "Incidente abierto manualmente para {$asset}.",
        };
    }

    /**
     * El monitorista deja su veredicto sobre la evaluación de IA al resolver.
     *
     * @param  array<string, mixed>  $life
     */
    private function recordVerdict(?stdClass $evaluation, array $life, User $by): void
    {
        if ($evaluation === null || in_array($life['status'], ['cancelled'], true)) {
            return;
        }

        DB::table('ai_event_evaluations')
            ->where('id', $evaluation->id)
            ->whereNull('operator_verdict')
            ->update([
                'operator_verdict' => $life['status'] === 'false_positive' ? 'false_positive' : 'confirmed',
                'operator_verdict_by' => $by->id,
                'operator_verdict_at' => $life['resolved_at'],
                'operator_verdict_note' => $life['status'] === 'false_positive' ? 'Descartado al cerrar el incidente.' : null,
            ]);
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $rows
     */
    private function linkPriorIncidents(int $incidentId, NormalizedEvent $event, CarbonImmutable $opened, array &$rows): void
    {
        if ($event->asset_id === null) {
            return;
        }

        foreach (array_slice(array_reverse($this->byAsset[$event->asset_id] ?? []), 0, 2) as $i => $prior) {
            if ($prior['opened']->diffInDays($opened) > 30) {
                continue;
            }

            $rows['event_related_incident_links'][] = [
                'team_id' => $this->ctx->team->id,
                'normalized_event_id' => $event->id,
                'incident_id' => $prior['id'],
                'relation_type' => $i === 0 ? 'same_asset_open_incident' : 'prior_similar_incident',
                'confidence_score' => $i === 0 ? 0.82 : 0.64,
                'created_at' => $opened,
                'updated_at' => $opened,
            ];
        }

        $this->byAsset[$event->asset_id][] = ['id' => $incidentId, 'opened' => $opened];
    }
}
