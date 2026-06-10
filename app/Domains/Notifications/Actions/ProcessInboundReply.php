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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
    public function execute(string $fromAddress, string $body, ?int $channelTeamId): ?string
    {
        if (preg_match(self::KEYWORD_PATTERN, $body, $matches) !== 1) {
            Log::info('Twilio inbound reply without recognizable keyword', ['from' => $fromAddress]);

            return null;
        }

        $keyword = strtoupper($matches[1]);
        $code = strtoupper($matches[2]);

        return DB::transaction(function () use ($keyword, $code, $fromAddress, $body, $channelTeamId) {
            $token = NotificationReplyToken::withoutGlobalScopes()
                ->where('token', $code)
                ->lockForUpdate()
                ->first();

            if ($token === null) {
                Log::info('Twilio inbound reply with unknown token', ['from' => $fromAddress, 'token' => $code]);

                return null;
            }

            // Tenant isolation: a token must only act through the Twilio
            // number of its own tenant (or a system-wide channel).
            if ($channelTeamId !== null && $token->team_id !== $channelTeamId) {
                Log::warning('Twilio inbound reply token does not belong to the receiving channel tenant', [
                    'token_id' => $token->id,
                ]);

                return null;
            }

            if ($this->normalizeAddress($token->address) !== $this->normalizeAddress($fromAddress)) {
                Log::warning('Twilio inbound reply from unexpected sender', ['token_id' => $token->id]);

                return null;
            }

            if ($token->isConsumed()) {
                return "Ya registramos tu respuesta para el incidente #{$token->incident_id}.";
            }

            if ($token->isExpired()) {
                return "El código {$code} ha expirado. Gestiona el incidente #{$token->incident_id} desde el portal.";
            }

            $incident = $token->incident()->withoutGlobalScopes()->first();

            if ($incident === null || $incident->isTerminal()) {
                $token->update(['consumed_at' => now(), 'consumed_action' => 'noop_terminal']);

                return "El incidente #{$token->incident_id} ya está cerrado.";
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
                'reply_payload_json' => ['from' => $fromAddress, 'body' => mb_substr($body, 0, 500)],
            ]);

            $this->recordAuditEntry->execute(
                actorType: $token->user_id !== null ? AuditActorType::User : AuditActorType::System,
                actorId: $token->user_id,
                action: 'incident.reply.'.strtolower($keyword),
                category: AuditCategory::Domain,
                entityType: 'incident',
                entityId: $incident->id,
                summary: "Respuesta {$keyword} vía {$via} de {$fromAddress} para el incidente #{$incident->id}.",
                teamId: $token->team_id,
                metadata: ['token_id' => $token->id, 'channel_type' => $via],
                sourceType: 'twilio_inbound',
                sourceReferenceId: (string) $token->id,
            );

            return $reply;
        });
    }

    private function confirm(Incident $incident, NotificationReplyToken $token, string $via): string
    {
        $this->acknowledgeIncident->execute($incident, $token->user_id, via: $via);

        return "✔ Incidente #{$incident->id} confirmado. SLA detenido.";
    }

    private function dismiss(Incident $incident, NotificationReplyToken $token, string $via): string
    {
        $this->closeIncident->execute(
            incident: $incident,
            resolutionCode: ResolutionCode::FalsePositive,
            summary: "Descartado como falsa alarma vía {$via} por {$token->address}.",
            resolvedByType: $token->user_id !== null ? IncidentCreatorType::User : IncidentCreatorType::System,
            resolvedById: $token->user_id,
        );

        return "✖ Incidente #{$incident->id} descartado como falsa alarma.";
    }

    private function escalate(Incident $incident, NotificationReplyToken $token, string $via): string
    {
        $this->escalateIncident->execute(
            incident: $incident,
            reason: "Escalado vía {$via} por {$token->address}.",
            escalatedByType: $token->user_id !== null ? IncidentCreatorType::User : IncidentCreatorType::System,
            escalatedById: $token->user_id,
        );

        return "▲ Incidente #{$incident->id} escalado.";
    }

    private function normalizeAddress(string $address): string
    {
        return preg_replace('/^whatsapp:/i', '', trim($address)) ?? $address;
    }
}
