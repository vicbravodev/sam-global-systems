<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Incidents\Actions\AcknowledgeIncident;
use App\Domains\Incidents\Actions\CloseIncident;
use App\Domains\Incidents\Actions\EscalateIncident;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\ResolutionCode;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Notifications\Models\NotificationReplyToken;
use App\Domains\Notifications\Support\DeliveryFeedbackPresenter;
use App\Support\LoggableCode;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Roadmap B9: maps an inbound SMS/WhatsApp reply ("SI-4F2A" / "NO-4F2A" /
 * "ESC-4F2A") back to its incident and executes the matching operation.
 * Unknown numbers, foreign-tenant tokens and stale codes are logged and
 * answered with silence; a second reply to the same token is idempotent.
 */
class ProcessInboundReply
{
    public const KEYWORD_PATTERN = '/\b(SI|NO|ESC)\s*-?\s*([2-9A-HJKMNP-Z]{4})\b/iu';

    public function __construct(
        private readonly AcknowledgeIncident $acknowledgeIncident,
        private readonly CloseIncident $closeIncident,
        private readonly EscalateIncident $escalateIncident,
        private readonly RecordAuditEntry $recordAuditEntry,
    ) {}

    /**
     * @return string|null Reply message for the sender, or null for silence.
     */
    public function execute(string $fromAddress, string $body): ?string
    {
        if (preg_match(self::KEYWORD_PATTERN, $body, $matches) !== 1) {
            SystemLog::skipped('notifications.inbound_reply.ignored', reason: 'no_keyword');

            return null;
        }

        // El patrón es /iu: el plegado Unicode deja pasar variantes como
        // `ſ` (U+017F) por `S`. mb_strtoupper las devuelve a ASCII y lo que
        // aun así no sea SI|NO|ESC se ignora como si no hubiera palabra clave.
        $keyword = match (mb_strtoupper($matches[1])) {
            'SI' => 'SI',
            'NO' => 'NO',
            'ESC' => 'ESC',
            default => null,
        };

        if ($keyword === null) {
            SystemLog::skipped('notifications.inbound_reply.ignored', reason: 'no_keyword');

            return null;
        }

        $code = mb_strtoupper($matches[2]);

        return DB::transaction(function () use ($keyword, $code, $fromAddress) {
            // Lookup de entrada: el webhook llega sin sesión y el tenant sale
            // del propio token, así que aquí no puede haber scope todavía.
            $token = NotificationReplyToken::withoutGlobalScopes()
                ->where('token', $code)
                ->lockForUpdate()
                ->first();

            if ($token === null) {
                SystemLog::skipped('notifications.inbound_reply.ignored', reason: 'unknown_token');

                return null;
            }

            // Every Twilio number is SAM's (platform account): the token
            // itself names the tenant, and it only acts when the reply comes
            // from the exact address it was issued to.
            if ($this->normalizeAddress($token->address) !== $this->normalizeAddress($fromAddress)) {
                SystemLog::degraded('notifications.inbound_reply.rejected', reason: 'unexpected_sender', input: ['token_id' => $token->id]);

                return null;
            }

            // Validado a quién pertenece el token, el resto del flujo corre
            // dentro de su tenant: el webhook entra sin sesión, así que hasta
            // aquí no había contexto que scopeara nada. Ver §2.1.
            TenantContext::set($token->team_id);

            // La referencia visible es el número por tenant, nunca el id
            // global (que revela el volumen de toda la plataforma).
            $incident = $token->incident()->first();
            $reference = $incident?->reference() ?? 'solicitado';

            // Nunca el remitente, el cuerpo, el código ni la dirección del
            // token: sólo ids y el canal.
            $logInput = ['token_id' => $token->id, 'incident_id' => $token->incident_id, 'channel_type' => $token->channel_type->value];

            if ($token->isConsumed()) {
                SystemLog::skipped('notifications.inbound_reply.ignored', reason: 'already_consumed', input: $logInput, result: [
                    'consumed_action' => LoggableCode::guard($token->consumed_action),
                ]);

                return "Ya registramos tu respuesta para el incidente {$reference}.";
            }

            if ($token->isExpired()) {
                SystemLog::skipped('notifications.inbound_reply.ignored', reason: 'token_expired', input: $logInput);

                return "El código {$code} ha expirado. Gestiona el incidente {$reference} desde el portal.";
            }

            if ($incident === null || $incident->isTerminal()) {
                $token->update(['consumed_at' => now(), 'consumed_action' => 'noop_terminal']);

                DB::afterCommit(fn () => SystemLog::skipped('notifications.inbound_reply.ignored', reason: 'incident_terminal', input: $logInput, result: [
                    'consumed_action' => 'noop_terminal',
                ]));

                return "El incidente {$reference} ya está cerrado.";
            }

            $via = $token->channel_type->value;

            $reply = match ($keyword) {
                'SI' => $this->confirm($incident, $token, $via),
                'NO' => $this->dismiss($incident, $token, $via),
                'ESC' => $this->escalate($incident, $token, $via),
            };

            $token->update([
                'consumed_at' => now(),
                'consumed_action' => $keyword,
                // Traza mínima: qué se hizo, por qué canal y quién. Nunca el
                // teléfono ni el texto libre (los ven los admins del tenant).
                'reply_payload_json' => ['action' => $keyword, 'channel_type' => $via, 'user_id' => $token->user_id],
            ]);

            $this->recordAuditEntry->execute(
                actorType: $token->user_id !== null ? AuditActorType::User : AuditActorType::System,
                actorId: $token->user_id,
                action: 'incident.reply.'.strtolower($keyword),
                category: AuditCategory::Domain,
                entityType: 'incident',
                entityId: $incident->id,
                summary: "Respuesta {$keyword} vía {$via} de {$this->replierLabel($token)} para el incidente {$incident->reference()}.",
                teamId: $token->team_id,
                metadata: ['token_id' => $token->id, 'channel_type' => $via],
                sourceType: 'twilio_inbound',
                sourceReferenceId: (string) $token->id,
            );

            $userLinked = $token->user_id !== null;

            DB::afterCommit(fn () => SystemLog::ok('notifications.inbound_reply.applied', input: $logInput, calc: [
                'keyword' => $keyword,
                'user_linked' => $userLinked,
            ], result: [
                'action' => match ($keyword) {
                    'SI' => 'acknowledge',
                    'NO' => 'dismiss',
                    'ESC' => 'escalate',
                },
            ]));

            return $reply;
        });
    }

    private function confirm(Incident $incident, NotificationReplyToken $token, string $via): string
    {
        $this->acknowledgeIncident->execute($incident, $token->user_id, via: $via);

        return "✔ Incidente {$incident->reference()} confirmado. SLA detenido.";
    }

    private function dismiss(Incident $incident, NotificationReplyToken $token, string $via): string
    {
        $this->closeIncident->execute(
            incident: $incident,
            resolutionCode: ResolutionCode::FalsePositive,
            summary: "Descartado como falsa alarma vía {$via} por {$this->replierLabel($token)}.",
            resolvedByType: $token->user_id !== null ? IncidentCreatorType::User : IncidentCreatorType::System,
            resolvedById: $token->user_id,
        );

        return "✖ Incidente {$incident->reference()} descartado como falsa alarma.";
    }

    private function escalate(Incident $incident, NotificationReplyToken $token, string $via): string
    {
        $this->escalateIncident->execute(
            incident: $incident,
            reason: "Escalado vía {$via} por {$this->replierLabel($token)}.",
            escalatedByType: $token->user_id !== null ? IncidentCreatorType::User : IncidentCreatorType::System,
            escalatedById: $token->user_id,
        );

        return "▲ Incidente {$incident->reference()} escalado.";
    }

    /**
     * Quién respondió, tal como lo leen los admins del tenant en auditoría,
     * resolución y timeline: el nombre del miembro al que se emitió el token
     * o, sin usuario vinculado, el teléfono enmascarado. Nunca el número
     * completo.
     */
    private function replierLabel(NotificationReplyToken $token): string
    {
        $name = $token->user_id !== null ? $token->user()->value('name') : null;

        if (is_string($name) && $name !== '') {
            return $name;
        }

        return DeliveryFeedbackPresenter::maskAddress($token->address) ?? 'un destinatario';
    }

    private function normalizeAddress(string $address): string
    {
        return preg_replace('/^whatsapp:/i', '', trim($address)) ?? $address;
    }
}
