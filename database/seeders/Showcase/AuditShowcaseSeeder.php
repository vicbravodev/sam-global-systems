<?php

namespace Database\Seeders\Showcase;

use App\Domains\Incidents\Models\Incident;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Models\Subscription;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Database\Seeders\Showcase\Support\ShowcaseRandom;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría: bitácora de todas las categorías (dominio, seguridad,
 * facturación, sistema, IA, integración) y todos los tipos de actor, la
 * cadena de eventos de dominio de cada incidente unida por
 * `correlation_id`, historial de cambios de estado y trazas distribuidas
 * del pipeline (spans por módulo).
 *
 * Tablas append-only: sólo inserciones. Idempotencia:
 *  - audit_logs: `signature = showcase:{team}:…` determinista +
 *    `insertOrIgnore` sobre el único (team_id, signature).
 *  - domain_event_logs / system_traces: `correlation_id` / `trace_id`
 *    deterministas por incidente; si ya existen se salta.
 *  - change_histories: sólo incidentes del showcase sin historial.
 */
class AuditShowcaseSeeder extends ShowcaseStep
{
    private const AGENTS = [
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 Safari/17.5',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0 Safari/537.36',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) Mobile/15E148',
    ];

    public function run(): void
    {
        $incidents = Incident::query()
            ->where('team_id', $this->ctx->team->id)
            ->whereNotNull('metadata_json->showcase_key')
            ->with(['status', 'type'])
            ->get();

        $this->incidentTrail($incidents);
        $this->platformTrail();
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     */
    private function incidentTrail($incidents): void
    {
        $logs = [];
        $domainEvents = [];
        $changes = [];
        $traces = [];
        $existingCorrelations = array_flip(DB::table('domain_event_logs')->where('team_id', $this->ctx->team->id)->whereNotNull('correlation_id')->distinct()->pluck('correlation_id')->map(fn ($id) => (string) $id)->all());
        $withHistory = array_flip(DB::table('change_histories')->where('team_id', $this->ctx->team->id)->where('entity_type', 'Incident')->distinct()->pluck('entity_id')->all());

        foreach ($incidents as $incident) {
            $opened = CarbonImmutable::parse($incident->opened_at);
            $random = ShowcaseRandom::forKey('audit:'.$incident->id);
            $sig = fn (string $what) => $this->ctx->key('audit', 'incident', (string) $incident->id, $what);
            $event = $incident->related_event_id !== null ? NormalizedEvent::query()->find($incident->related_event_id) : null;

            $logs[] = $this->log('incident.created', 'domain', $incident->created_by_type?->value === 'user' ? 'user' : 'ai', $incident->created_by_id, 'Incident', $incident->id, "Incidente abierto: {$incident->title}", $opened, $sig('created'));

            if ($incident->related_decision_id !== null) {
                $logs[] = $this->log('decision.made', 'ai', 'ai', null, 'Decision', $incident->related_decision_id, 'El motor decidió abrir incidente con base en la evaluación de IA.', $opened->subSecond(), $sig('decision'));
            }

            if ($incident->acknowledged_at !== null) {
                $logs[] = $this->log('incident.acknowledged', 'domain', 'user', $incident->acknowledged_by, 'Incident', $incident->id, 'Incidente reconocido por el monitorista.', CarbonImmutable::parse($incident->acknowledged_at), $sig('ack'), user: true);
            }

            if ($incident->resolved_at !== null) {
                $logs[] = $this->log('incident.resolved', 'domain', 'user', $incident->acknowledged_by, 'Incident', $incident->id, 'Incidente resuelto: '.$incident->status?->code, CarbonImmutable::parse($incident->resolved_at), $sig('resolved'), user: true);
            }

            $correlation = $this->uuid('corr', $incident->id);

            if (! isset($existingCorrelations[$correlation])) {
                $chain = [
                    ['App\\Domains\\Normalization\\Events\\EventNormalized', 'NormalizedEvent', $incident->related_event_id, 0],
                    ['App\\Domains\\Context\\Events\\EventContextBuilt', 'NormalizedEvent', $incident->related_event_id, 1],
                    ['App\\Domains\\AI\\Events\\AIEvaluationCompleted', 'NormalizedEvent', $incident->related_event_id, 4],
                    ['App\\Domains\\Decisions\\Events\\DecisionMade', 'Decision', $incident->related_decision_id, 5],
                    ['App\\Domains\\Incidents\\Events\\IncidentCreated', 'Incident', $incident->id, 6],
                ];
                $previous = null;

                foreach ($chain as $i => [$name, $aggregate, $aggregateId, $offset]) {
                    if ($aggregateId === null) {
                        continue;
                    }

                    $causation = $this->uuid("cause{$i}", $incident->id);
                    $domainEvents[] = [
                        'team_id' => $this->ctx->team->id,
                        'event_name' => $name,
                        'aggregate_type' => $aggregate,
                        'aggregate_id' => $aggregateId,
                        'payload_json' => ['incident_id' => $incident->id, 'showcase' => true],
                        'occurred_at' => $opened->subSeconds(6 - $offset),
                        'correlation_id' => $correlation,
                        'causation_id' => $previous,
                        'created_at' => $opened->subSeconds(6 - $offset),
                    ];
                    $previous = $causation;
                }

                $this->traceFor($incident, $opened, $random, $traces);
            }

            if (! isset($withHistory[$incident->id])) {
                $changes[] = $this->change($incident, 'created', null, ['status' => 'open'], $opened);

                if ($incident->acknowledged_at !== null) {
                    $changes[] = $this->change($incident, 'status_changed', ['status' => 'open'], ['status' => 'in_review'], CarbonImmutable::parse($incident->acknowledged_at), $incident->acknowledged_by);
                }

                if ($incident->resolved_at !== null) {
                    $changes[] = $this->change($incident, 'status_changed', ['status' => 'in_review'], ['status' => $incident->status?->code], CarbonImmutable::parse($incident->resolved_at), $incident->acknowledged_by);
                }
            }
        }

        $this->insertIgnore('audit_logs', $logs);
        $this->bulkInsert('domain_event_logs', $domainEvents, timestamps: false);
        $this->bulkInsert('change_histories', $changes, timestamps: false);
        $this->bulkInsert('system_traces', $traces, timestamps: false);
    }

    /**
     * Seguridad, facturación, integración, IA y sistema: la vida del tenant
     * alrededor de la operación.
     */
    private function platformTrail(): void
    {
        $logs = [];
        $team = $this->ctx->team;

        for ($day = $this->ctx->startDay(); $day->lessThanOrEqualTo($this->ctx->now); $day = $day->addDay()) {
            $ymd = $day->format('Ymd');
            $random = ShowcaseRandom::forKey($this->ctx->key('audit-day', $ymd));

            foreach ($this->ctx->users as $role => $user) {
                if ($day->isWeekend() && $role !== 'monitor_night' || ! $random->chance($role === 'viewer' ? 0.2 : 0.85)) {
                    continue;
                }

                $at = $day->setTime($role === 'monitor_night' ? 21 : 7, $random->int(30, 59));

                if ($at->greaterThan($this->ctx->now)) {
                    continue;
                }

                $logs[] = $this->log('auth.login', 'security', 'user', $user->id, 'User', $user->id, "Inicio de sesión de {$user->email}", $at, $this->ctx->key('audit', 'login', $ymd, (string) $user->id), user: true, email: $user->email, random: $random);
            }

            if ($random->chance(0.12)) {
                $user = $random->pick(array_values($this->ctx->users));
                $at = $day->setTime(3, $random->int(0, 59));
                $logs[] = $this->log('auth.login_failed', 'security', 'system', null, 'User', $user->id, "3 intentos fallidos de contraseña para {$user->email} desde una IP desconocida", $at, $this->ctx->key('audit', 'login-failed', $ymd), email: $user->email, ip: '189.203.'.$random->int(1, 254).'.'.$random->int(1, 254));
            }

            if ($random->chance(0.3)) {
                $at = $day->setTime(2, 0, 5);
                $logs[] = $this->log('integration.sync_completed', 'integration', 'job', null, 'TenantIntegration', $this->ctx->integration?->id, 'Sincronización completa de activos y conductores con Samsara.', $at, $this->ctx->key('audit', 'sync', $ymd));
            }

            if ($random->chance(0.08)) {
                $at = $day->setTime(14, $random->int(0, 59));
                $logs[] = $this->log('webhook.signature_invalid', 'integration', 'webhook_source', null, 'TenantIntegration', $this->ctx->integration?->id, 'Webhook rechazado: firma HMAC inválida.', $at, $this->ctx->key('audit', 'webhook', $ymd), ip: '52.37.'.$random->int(1, 254).'.'.$random->int(1, 254));
            }

            if ($random->chance(0.15)) {
                $at = $day->setTime(4, 30);
                $logs[] = $this->log('ai.quota_warning', 'ai', 'system', null, 'Team', $team->id, 'El consumo de tokens de IA superó el 80 % de la cuota mensual.', $at, $this->ctx->key('audit', 'ai-quota', $ymd));
            }

            if ($day->day === 1) {
                $billing = $this->ctx->users['billing'] ?? $this->ctx->user('admin');
                $logs[] = $this->log('invoice.generated', 'billing', 'job', null, 'InvoiceSnapshot', null, 'Se generó la factura del periodo '.$day->subMonth()->format('m/Y').'.', $day->setTime(2, 15), $this->ctx->key('audit', 'invoice', $ymd));
                $logs[] = $this->log('invoice.receipt_uploaded', 'billing', 'user', $billing->id, 'InvoiceSnapshot', null, 'Se subió el comprobante de transferencia SPEI.', $day->addDays(3)->setTime(11, 20), $this->ctx->key('audit', 'receipt', $ymd), user: true, email: $billing->email);
            }
        }

        $admin = $this->ctx->user('admin');
        $one = [
            ['team.member_invited', 'security', 'Se invitó a coordinador.nocturno como miembro.', 'Team', $team->id, 5],
            ['role.permissions_changed', 'security', 'Se dio el permiso incidents.resolve al rol Monitorista.', 'Team', $team->id, 20],
            ['auth.two_factor_enabled', 'security', "{$admin->email} activó la verificación en dos pasos.", 'User', $admin->id, 40],
            ['config.ai_profile_updated', 'system', 'Perfil de IA: tolerancia a falsos positivos → media.', 'Team', $team->id, 12],
            ['automation.workflow_updated', 'system', 'Se editó el workflow «Protocolo de emergencia».', 'Team', $team->id, 8],
            ['subscription.renewed', 'billing', 'Suscripción renovada por un mes más.', 'Subscription', Subscription::query()->where('team_id', $team->id)->value('id'), 1],
        ];

        foreach ($one as [$action, $category, $summary, $entity, $entityId, $daysAgo]) {
            $at = $this->ctx->now->subDays(min($daysAgo, $this->ctx->days - 1))->setTime(10, 5);
            $logs[] = $this->log($action, $category, $category === 'billing' ? 'system' : 'user', $category === 'billing' ? null : $admin->id, $entity, $entityId, $summary, $at, $this->ctx->key('audit', $action), user: $category !== 'billing', email: $admin->email);
        }

        $this->insertIgnore('audit_logs', $logs);
    }

    /**
     * @return array<string, mixed>
     */
    private function log(string $action, string $category, string $actorType, ?int $actorId, string $entity, ?int $entityId, string $summary, CarbonImmutable $at, string $signature, bool $user = false, ?string $email = null, ?ShowcaseRandom $random = null, ?string $ip = null): array
    {
        $random ??= ShowcaseRandom::forKey($signature);

        return [
            'team_id' => $this->ctx->team->id,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $action,
            'category' => $category,
            'entity_type' => $entity,
            'entity_id' => $entityId,
            'source_type' => $user ? 'web' : ($actorType === 'webhook_source' ? 'webhook' : 'pipeline'),
            'source_reference_id' => null,
            'signature' => $signature,
            'summary' => $summary,
            'metadata_json' => array_filter(['actor_email' => $email, 'showcase' => true], fn (string|bool|null $value): bool => $value !== null && $value !== ''),
            'ip_address' => $ip ?? ($user ? '201.141.'.$random->int(1, 254).'.'.$random->int(1, 254) : null),
            'user_agent' => $user ? $random->pick(self::AGENTS) : null,
            'occurred_at' => $at,
            'created_at' => $at,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     * @return array<string, mixed>
     */
    private function change(Incident $incident, string $type, ?array $before, array $after, CarbonImmutable $at, ?int $userId = null): array
    {
        return [
            'team_id' => $this->ctx->team->id,
            'entity_type' => 'Incident',
            'entity_id' => $incident->id,
            'changed_by_type' => $userId !== null ? 'user' : 'system',
            'changed_by_id' => $userId,
            'change_type' => $type,
            'before_json' => $before,
            'after_json' => $after,
            'changed_fields_json' => array_keys($after),
            'reason' => null,
            'occurred_at' => $at,
            'created_at' => $at,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $traces
     */
    private function traceFor(Incident $incident, CarbonImmutable $opened, ShowcaseRandom $random, array &$traces): void
    {
        $traceId = $this->uuid('trace', $incident->id);
        $root = $this->uuid('span-root', $incident->id);
        $cursor = $opened->subSeconds(8);
        $failedModule = $random->chance(0.03) ? 'context' : null;

        $traces[] = $this->span($traceId, $root, null, 'ingestion', 'receive_webhook', $cursor, $random->int(40, 180), null);

        foreach (['normalization' => 'normalize_event', 'context' => 'build_context', 'ai' => 'evaluate_event', 'decisions' => 'decide', 'incidents' => 'create_incident'] as $module => $operation) {
            $duration = $module === 'ai' ? $random->int(900, 6_000) : $random->int(15, 400);
            $cursor = $cursor->addMilliseconds($random->int(20, 300));
            $traces[] = $this->span($traceId, $this->uuid("span-{$module}", $incident->id), $root, $module, $operation, $cursor, $duration, $failedModule === $module ? 'Timeout consultando la ubicación en vivo; se usó la última conocida.' : null);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function span(string $traceId, string $spanId, ?string $parent, string $module, string $operation, CarbonImmutable $start, int $durationMs, ?string $error): array
    {
        return [
            'trace_id' => $traceId,
            'span_id' => $spanId,
            'parent_span_id' => $parent,
            'team_id' => $this->ctx->team->id,
            'module_name' => $module,
            'operation_name' => $operation,
            'status' => $error !== null && $error !== '' ? 'failed' : 'completed',
            'started_at' => $start,
            'finished_at' => $start->addMilliseconds($durationMs),
            'duration_ms' => $durationMs,
            'input_reference_json' => null,
            'output_reference_json' => null,
            'error_message' => $error,
            'metadata_json' => ['showcase' => true],
            'created_at' => $start,
        ];
    }

    /**
     * UUID v4 determinista (mismo incidente → mismo id en cada corrida).
     */
    private function uuid(string $salt, int $id): string
    {
        $hex = md5("{$this->ctx->team->id}:{$salt}:{$id}");

        return sprintf('%s-%s-4%s-%s%s-%s',
            substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 13, 3),
            dechex(8 + (hexdec($hex[16]) & 3)), substr($hex, 17, 3), substr($hex, 20, 12));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function insertIgnore(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            $chunk = array_map(fn (array $row) => array_map(fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v, $row), $chunk);
            $this->ctx->count($table, DB::table($table)->insertOrIgnore($chunk));
        }
    }
}
