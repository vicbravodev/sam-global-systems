<?php

namespace App\Domains\Incidents\Actions;

use App\Domains\Incidents\Enums\IncidentPriorityCode;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use App\Domains\TenantConfig\Models\TenantEscalationConfig;
use App\Support\LoggableCode;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Avisa a un nivel de la escalación del tenant. Lo usan el watchdog de SLA,
 * la confirmación DTMF 1 ("es real") y la llamada de verificación imposible.
 *
 * - Paso con contactos: se les escribe directo por los canales del paso.
 * - Paso sin contactos (el pack de fábrica): un escalón del tenant según el
 *   nivel —0 la persona en turno, 1 supervisores/monitoristas, 2+ admins—,
 *   nunca el equipo entero (decisión 2026-10-01). En un incidente crítico o alto van por los
 *   canales del paso (voz/SMS a su teléfono verificado, decisión
 *   2026-09-28: el teléfono es el canal de arranque); en uno medio o bajo,
 *   sólo app y correo.
 *
 * Corre suelta (watchdog) o dentro de una transacción (escalamiento de una
 * verificación imposible desde un listener de IncidentCreated): sus líneas van
 * por DB::afterCommit y nunca llevan contactos, destinatarios ni el texto.
 */
class NotifyEscalationLevel
{
    /** @var list<string> */
    public const array AUDIENCES = ResolveEscalationAudience::AUDIENCES;

    public function __construct(
        private readonly SendNotification $sendNotification,
        private readonly ResolveEscalationAudience $resolveAudience,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function steps(int $teamId): array
    {
        $config = TenantContext::for($teamId, fn () => TenantEscalationConfig::query()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->latest('id')
            ->first());

        $steps = $config?->steps_json;

        return is_array($steps) ? array_values($steps) : [];
    }

    public function execute(
        Incident $incident,
        int $level,
        string $eventKey,
        string $notificationType,
        string $subject,
        string $body,
        ?NotificationPriority $priority = null,
        ?string $spoken = null,
    ): void {
        $teamId = $incident->team_id;
        $incident->loadMissing(['priority', 'type']);
        $step = $this->steps($teamId)[$level] ?? null;

        $payload = [
            'incident_id' => $incident->id,
            'incident_reference' => $incident->reference(),
            'incident_type' => $incident->type?->code,
            'severity' => $incident->priority?->code,
            'incident_title' => $incident->title,
            'escalation_level' => $level,
        ];

        // Lo que se lee en una llamada (VoiceNotificationDriver): un texto
        // pensado para oírse, no el SMS en voz alta.
        if ($spoken !== null && $spoken !== '') {
            $payload['spoken'] = $spoken;
        }

        $channels = array_values(array_filter(
            (array) ($step['channels'] ?? []),
            fn ($channel) => is_string($channel) && ChannelType::tryFrom($channel) !== null,
        ));

        // SMS o WhatsApp, nunca ambos en el mismo paso: el mismo aviso dos
        // veces al mismo teléfono es ruido y doble costo. Gana el SMS.
        $bothMessaging = in_array(ChannelType::Sms->value, $channels, true) && in_array(ChannelType::Whatsapp->value, $channels, true);

        if ($bothMessaging) {
            $channels = array_values(array_filter($channels, fn (string $channel) => $channel !== ChannelType::Whatsapp->value));
        }

        $contacts = array_values(array_filter(
            (array) ($step['contacts'] ?? []),
            fn ($contact) => is_string($contact) && $contact !== '',
        ));

        $logInput = [
            'incident_id' => $incident->id,
            'level' => $level,
            'notification_type' => LoggableCode::guard($notificationType),
        ];
        $urgent = null;
        $audience = null;

        if ($contacts !== []) {
            $payload['recipients'] = array_map(fn (string $address) => [
                'recipient_type' => 'external_contact',
                'address' => $address,
            ], $contacts);

            if ($channels !== []) {
                $payload['force_channels'] = $channels;
            }
        } else {
            $requested = is_string($step['audience'] ?? null) && in_array($step['audience'], self::AUDIENCES, true)
                ? $step['audience']
                : ResolveEscalationAudience::defaultFor($level);
            $audience = $this->resolveAudience->execute($teamId, $requested);

            if ($audience['recipients'] === []) {
                $skippedCalc = ['step_present' => $step !== null, 'contacts_count' => 0, 'audience' => $audience['audience'], 'audience_fallback' => $audience['fallback']];
                DB::afterCommit(fn () => SystemLog::skipped('incidents.escalation_level.notified',
                    reason: 'no_supervisors',
                    input: $logInput,
                    calc: $skippedCalc,
                ));

                return;
            }

            $urgent = in_array($incident->priority?->code, [IncidentPriorityCode::Critical->value, IncidentPriorityCode::High->value], true);

            $payload['recipients'] = $audience['recipients'];
            $payload['force_channels'] = $urgent && $channels !== []
                ? array_values(array_unique([...$channels, ChannelType::Web->value]))
                : [ChannelType::Web->value, ChannelType::Email->value];
        }

        $notification = $this->sendNotification->execute(
            teamId: $teamId,
            notificationType: $notificationType,
            sourceType: NotificationSourceType::Incident,
            sourceReferenceId: (string) $incident->id,
            priority: $priority ?? NotificationPriority::fromIncidentPriority($incident->priority?->code),
            triggeredByType: NotificationTriggeredByType::System,
            triggeredById: null,
            eventKey: $eventKey,
            payload: $payload,
            subject: $subject,
            bodyPreview: $body,
        );

        $notifiedLine = [
            'input' => $logInput,
            'calc' => [
                'step_present' => $step !== null,
                'recipients_source' => $contacts !== [] ? 'step_contacts' : 'audience',
                'audience' => $audience['audience'] ?? null,
                'audience_requested' => $audience['requested'] ?? null,
                'audience_fallback' => $audience['fallback'] ?? null,
                'whatsapp_dropped_for_sms' => $bothMessaging,
                'contacts_count' => count($contacts),
                'recipients_count' => count($payload['recipients']),
                'step_channel_types' => $channels,
                'urgent' => $urgent,
                'forced_channel_types' => $payload['force_channels'] ?? null,
            ],
            'result' => ['notification_id' => $notification->id],
        ];
        DB::afterCommit(fn () => SystemLog::ok('incidents.escalation_level.notified', ...$notifiedLine));
    }
}
