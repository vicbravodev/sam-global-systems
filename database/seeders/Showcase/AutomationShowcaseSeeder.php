<?php

namespace Database\Seeders\Showcase;

use App\Domains\Automation\Models\ActionTemplate;
use App\Domains\Automation\Models\AutomationWorkflow;
use App\Domains\Incidents\Models\Incident;
use Carbon\CarbonImmutable;
use Database\Seeders\Showcase\Support\ShowcaseRandom;
use Illuminate\Support\Facades\DB;

/**
 * Automatización: plantillas de acción, workflows (con pasos de
 * escalamiento) y su historial de ejecuciones sobre los incidentes del
 * showcase — completadas, fallidas con el error real del proveedor,
 * reintentando, esperando confirmación humana y canceladas — con su log.
 *
 * SEGURIDAD: en un tenant con datos reales los workflows se crean
 * INACTIVOS: activos dispararían SMS/llamadas reales sobre los incidentes
 * que siga generando el pipeline en vivo.
 *
 * Idempotencia: workflows y plantillas por `code`; ejecuciones sólo para
 * incidentes del showcase que aún no tienen ninguna.
 */
class AutomationShowcaseSeeder extends ShowcaseStep
{
    /** @var array<string, AutomationWorkflow> */
    private array $workflows = [];

    public function run(): void
    {
        $this->seedTemplates();
        $this->seedWorkflows();

        if (! $this->ctx->light) {
            $this->seedExecutions();
        }
    }

    private function seedTemplates(): void
    {
        $templates = [
            ['sms-alerta-critica', 'SMS de alerta crítica', 'send_sms', 'sms', null, '[SAM] {{incident.title}} — atiende en la consola: {{incident.url}}'],
            ['email-resumen-incidente', 'Correo de resumen de incidente', 'send_email', 'email', 'Incidente {{incident.code}}: {{incident.title}}', "Hola {{recipient.name}},\n\nSe abrió el incidente {{incident.code}} ({{incident.priority}}).\n\n{{incident.summary}}"],
            ['whatsapp-monitorista', 'WhatsApp al monitorista', 'send_whatsapp', 'whatsapp', null, '🚨 {{incident.title}} · prioridad {{incident.priority}}. Responde 1 para tomarlo.'],
            ['webhook-cliente', 'Webhook al sistema del cliente', 'call_webhook', null, null, '{"incident":"{{incident.code}}","status":"{{incident.status}}"}'],
        ];

        foreach ($templates as [$code, $name, $type, $channel, $subject, $body]) {
            $exists = ActionTemplate::query()->where('team_id', $this->ctx->team->id)->where('code', $code)->exists();

            if ($exists) {
                continue;
            }

            ActionTemplate::query()->create([
                'team_id' => $this->ctx->team->id,
                'code' => $code,
                'name' => $name,
                'action_type' => $type,
                'channel' => $channel,
                'subject_template' => $subject,
                'body_template' => $body,
                'parameters_schema_json' => ['incident' => 'object', 'recipient' => 'object'],
                'config_json' => $type === 'call_webhook' ? ['url' => 'https://hooks.cliente.example/sam', 'timeout' => 10] : null,
                'is_active' => true,
            ]);
            $this->ctx->count('action_templates');
        }
    }

    private function seedWorkflows(): void
    {
        $live = ! $this->ctx->hasRealData;
        $monitor = (string) $this->ctx->user('monitor')->id;

        $definitions = [
            'protocolo-emergencia' => [
                'Protocolo de emergencia', 'Pánico o colisión confirmados: SMS y llamada al supervisor, correo a dirección y escalamiento a los 5 min.',
                'decision_outcome', ['decision_code' => 'ESCALATE'], 'active',
                [
                    ['order' => 1, 'action_type' => 'send_sms', 'execution_mode' => 'async', 'target_type' => 'role', 'target_reference' => 'supervisor', 'delay_seconds' => 0, 'template_code' => 'sms-alerta-critica'],
                    ['order' => 2, 'action_type' => 'assign_incident', 'execution_mode' => 'sync', 'target_type' => 'user', 'target_reference' => $monitor, 'delay_seconds' => 0],
                    ['order' => 3, 'action_type' => 'send_email', 'execution_mode' => 'async', 'target_type' => 'role', 'target_reference' => 'tenant_admin', 'delay_seconds' => 60, 'template_code' => 'email-resumen-incidente'],
                    ['order' => 4, 'action_type' => 'escalate', 'execution_mode' => 'deferred', 'target_type' => 'role', 'target_reference' => 'tenant_admin', 'delay_seconds' => 300],
                ],
            ],
            'aviso-incidente-alto' => [
                'Aviso de incidente alto', 'Incidentes de prioridad alta: WhatsApp al monitorista de guardia.',
                'incident_created', ['severity' => 'high'], 'active',
                [
                    ['order' => 1, 'action_type' => 'send_whatsapp', 'execution_mode' => 'async', 'target_type' => 'role', 'target_reference' => 'monitorista', 'delay_seconds' => 0, 'template_code' => 'whatsapp-monitorista'],
                ],
            ],
            'coaching-cumplimiento' => [
                'Coaching por cumplimiento', 'Violaciones de cumplimiento: correo al supervisor y ticket de seguimiento.',
                'incident_created', ['incident_type' => 'compliance_violation'], 'active',
                [
                    ['order' => 1, 'action_type' => 'send_email', 'execution_mode' => 'async', 'target_type' => 'role', 'target_reference' => 'supervisor', 'delay_seconds' => 0, 'template_code' => 'email-resumen-incidente'],
                    ['order' => 2, 'action_type' => 'create_ticket', 'execution_mode' => 'async', 'target_type' => 'external', 'target_reference' => 'mesa-de-ayuda', 'delay_seconds' => 0],
                ],
            ],
            'webhook-geocerca-cliente' => [
                'Webhook al cliente por geocerca', 'Violación de geocerca: avisa al sistema del cliente.',
                'incident_created', ['incident_type' => 'geofence_breach'], 'active',
                [
                    ['order' => 1, 'action_type' => 'call_webhook', 'execution_mode' => 'async', 'target_type' => 'external', 'target_reference' => 'webhook-cliente', 'delay_seconds' => 0, 'template_code' => 'webhook-cliente'],
                ],
            ],
            'revision-camara' => [
                'Revisión de cámara obstruida', 'Pide confirmación humana antes de marcar la unidad en mantenimiento.',
                'incident_created', ['incident_type' => 'camera_obstructed'], 'active',
                [
                    ['order' => 1, 'action_type' => 'request_human_review', 'execution_mode' => 'requires_confirmation', 'target_type' => 'role', 'target_reference' => 'supervisor', 'delay_seconds' => 0],
                    ['order' => 2, 'action_type' => 'update_asset_state', 'execution_mode' => 'requires_confirmation', 'target_type' => 'asset', 'target_reference' => 'maintenance', 'delay_seconds' => 0],
                ],
            ],
            'escalamiento-direccion' => [
                'Escalamiento a dirección (borrador)', 'Propuesta: avisar por SMS a dirección cuando un incidente escala.',
                'incident_escalated', ['new_status' => 'escalated'], 'draft',
                [
                    ['order' => 1, 'action_type' => 'send_sms', 'execution_mode' => 'async', 'target_type' => 'role', 'target_reference' => 'tenant_admin', 'delay_seconds' => 0],
                ],
            ],
        ];

        foreach ($definitions as $code => [$name, $description, $trigger, $conditions, $status, $steps]) {
            $workflow = AutomationWorkflow::query()->where('team_id', $this->ctx->team->id)->where('code', $code)->first();

            if ($workflow === null) {
                $active = $live && $status === 'active';
                $workflow = AutomationWorkflow::query()->create([
                    'team_id' => $this->ctx->team->id,
                    'code' => $code,
                    'name' => $name,
                    'description' => $description.($live ? '' : ' (Inactivo: sembrado por el showcase sobre un tenant con datos reales.)'),
                    'trigger_type' => $trigger,
                    'trigger_conditions_json' => $conditions,
                    'status' => $active ? 'active' : ($status === 'draft' ? 'draft' : 'inactive'),
                    'version' => 1,
                    'steps_json' => $steps,
                    'is_active' => $active,
                    'created_at' => $this->ctx->startDay()->subDays(20),
                ]);
                $this->ctx->count('automation_workflows');

                foreach ($steps as $step) {
                    DB::table('escalation_steps')->insert([
                        'automation_workflow_id' => $workflow->id,
                        'step_order' => $step['order'],
                        'step_type' => match ($step['action_type']) {
                            'assign_incident' => 'assign',
                            'escalate' => 'escalate',
                            'create_ticket' => 'create_ticket',
                            'request_human_review', 'update_asset_state' => 'request_confirmation',
                            'call_webhook' => 'call_external_system',
                            default => 'notify',
                        },
                        'target_type' => $step['target_type'],
                        'target_reference' => $step['target_reference'],
                        'delay_seconds' => $step['delay_seconds'],
                        'conditions_json' => json_encode($conditions),
                        'fallback_action' => $step['action_type'] === 'send_sms' ? 'send_email' : null,
                        'created_at' => $this->ctx->now,
                        'updated_at' => $this->ctx->now,
                    ]);
                    $this->ctx->count('escalation_steps');
                }
            }

            $this->workflows[$code] = $workflow;
        }
    }

    private function seedExecutions(): void
    {
        $incidents = Incident::query()
            ->where('team_id', $this->ctx->team->id)
            ->whereNotNull('metadata_json->showcase_key')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('workflow_executions')
                ->where('workflow_executions.source_type', 'incident')
                ->whereColumn('workflow_executions.source_reference_id', DB::raw('CAST(incidents.id AS VARCHAR)')))
            ->with(['type', 'priority', 'status'])
            ->orderBy('opened_at')
            ->get();

        $actions = [];
        $logs = [];

        foreach ($incidents as $incident) {
            $workflow = $this->workflowFor($incident);

            if ($workflow === null) {
                continue;
            }

            $random = ShowcaseRandom::forKey('automation:'.$incident->id);
            $started = CarbonImmutable::parse($incident->opened_at)->addSeconds(2);
            $open = ! (bool) $incident->status?->is_terminal;
            $failedStep = $random->chance(0.08) ? $random->int(1, count($workflow->steps_json)) : null;
            $status = match (true) {
                $failedStep !== null => 'failed',
                $open && $started->diffInMinutes($this->ctx->now) < 10 => 'running',
                $incident->status?->code === 'cancelled' => 'cancelled',
                default => 'completed',
            };

            $execution = DB::table('workflow_executions')->insertGetId([
                'automation_workflow_id' => $workflow->id,
                'team_id' => $this->ctx->team->id,
                'source_type' => 'incident',
                'source_reference_id' => (string) $incident->id,
                'status' => $status,
                'started_at' => $started,
                'completed_at' => $status === 'running' ? null : $started->addSeconds($random->int(3, 400)),
                'created_at' => $started,
                'updated_at' => $started,
            ]);
            $this->ctx->count('workflow_executions');

            foreach ($workflow->steps_json as $step) {
                $at = $started->addSeconds((int) $step['delay_seconds'] + $random->int(0, 4));
                $stepStatus = match (true) {
                    $failedStep === $step['order'] => 'failed',
                    $failedStep !== null && $step['order'] > $failedStep => 'cancelled',
                    $at->greaterThan($this->ctx->now) => 'pending',
                    $step['execution_mode'] === 'requires_confirmation' && $open => 'pending',
                    $step['execution_mode'] === 'deferred' && ! $open && $random->chance(0.5) => 'cancelled',
                    $random->chance(0.04) => 'retrying',
                    default => 'completed',
                };
                $error = $stepStatus === 'failed' ? $this->errorFor($step['action_type'], $random) : null;

                $actions[] = [
                    'row' => [
                        'team_id' => $this->ctx->team->id,
                        'action_type' => $step['action_type'],
                        'source_type' => 'workflow',
                        'source_reference_id' => (string) $execution,
                        'incident_id' => $incident->id,
                        'decision_id' => $incident->related_decision_id,
                        'automation_workflow_id' => $workflow->id,
                        'action_template_id' => isset($step['template_code']) ? ActionTemplate::query()->where('team_id', $this->ctx->team->id)->where('code', $step['template_code'])->value('id') : null,
                        'status' => $stepStatus,
                        'execution_mode' => $step['execution_mode'],
                        'target_type' => $step['target_type'],
                        'target_reference' => $step['target_reference'],
                        'payload_json' => json_encode(['incident_id' => $incident->id, 'step' => $step['order']]),
                        'response_json' => $stepStatus === 'completed' ? json_encode(['stub' => false, 'provider_reference' => 'SM'.md5("showcase-{$incident->id}-{$step['order']}")]) : null,
                        'error_message' => $error ?? ($stepStatus === 'retrying' ? 'Timeout del proveedor; se reintenta con backoff.' : null),
                        'attempts' => match ($stepStatus) {
                            'failed' => 3,
                            'retrying' => 2,
                            'pending', 'cancelled' => 0,
                            default => 1,
                        },
                        'executed_at' => in_array($stepStatus, ['completed', 'failed', 'retrying'], true) ? $at : null,
                        'created_at' => $at,
                        'updated_at' => $at,
                    ],
                    'logs' => $this->logsFor($stepStatus, $step['action_type'], $error, $at),
                ];
            }
        }

        foreach ($actions as $action) {
            $id = DB::table('action_executions')->insertGetId($action['row']);
            $this->ctx->count('action_executions');

            foreach ($action['logs'] as $log) {
                $logs[] = ['action_execution_id' => $id, ...$log];
            }
        }

        $this->bulkInsert('action_execution_logs', $logs, timestamps: false);
    }

    private function workflowFor(Incident $incident): ?AutomationWorkflow
    {
        $type = $incident->type?->code;
        $priority = $incident->priority?->code;

        return match (true) {
            in_array($type, ['panic_emergency', 'collision'], true) && $incident->related_decision_id !== null => $this->workflows['protocolo-emergencia'] ?? null,
            $type === 'compliance_violation' => $this->workflows['coaching-cumplimiento'] ?? null,
            $type === 'geofence_breach' => $this->workflows['webhook-geocerca-cliente'] ?? null,
            $type === 'camera_obstructed' => $this->workflows['revision-camara'] ?? null,
            $priority === 'high' => $this->workflows['aviso-incidente-alto'] ?? null,
            default => null,
        };
    }

    private function errorFor(string $actionType, ShowcaseRandom $random): string
    {
        return match ($actionType) {
            'send_sms' => $random->pick(['Twilio 21610: el destinatario respondió STOP y no acepta SMS.', 'Twilio 21211: número de teléfono inválido (+52 sin 10 dígitos).']),
            'send_whatsapp' => 'Twilio 63016: fuera de la ventana de 24 h; se requiere plantilla aprobada.',
            'send_email' => 'SMTP 550: buzón del destinatario lleno.',
            'call_webhook' => 'El webhook del cliente respondió HTTP 502 tras 3 intentos.',
            'create_ticket' => 'La mesa de ayuda rechazó el ticket: campo "categoría" obligatorio.',
            default => 'La acción no pudo completarse.',
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function logsFor(string $status, string $actionType, ?string $error, CarbonImmutable $at): array
    {
        $logs = [['log_type' => 'info', 'message' => "Acción {$actionType} encolada.", 'payload_json' => null, 'created_at' => $at, 'updated_at' => $at]];

        if ($status === 'completed') {
            $logs[] = ['log_type' => 'external_response', 'message' => 'El proveedor aceptó la solicitud.', 'payload_json' => json_encode(['http_status' => 200]), 'created_at' => $at->addSeconds(1), 'updated_at' => $at->addSeconds(1)];
        }

        if (in_array($status, ['failed', 'retrying'], true)) {
            $logs[] = ['log_type' => 'retry', 'message' => 'Reintento 1 de 3 con backoff de 30 s.', 'payload_json' => null, 'created_at' => $at->addSeconds(30), 'updated_at' => $at->addSeconds(30)];
        }

        if ($status === 'failed') {
            $logs[] = ['log_type' => 'error', 'message' => (string) $error, 'payload_json' => null, 'created_at' => $at->addSeconds(90), 'updated_at' => $at->addSeconds(90)];
        }

        if ($status === 'cancelled') {
            $logs[] = ['log_type' => 'warning', 'message' => 'Cancelada: el incidente se resolvió antes de ejecutar el paso.', 'payload_json' => null, 'created_at' => $at, 'updated_at' => $at];
        }

        return $logs;
    }
}
