<?php

namespace App\Domains\Ingestion\Notifications;

use App\Domains\Ingestion\Models\PipelineFailureAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso de que una etapa crítica del pipeline falló sin remedio (o agotó sus
 * rescates). Va por Laravel Notifications directo (correo + in-app), nunca por
 * el dominio Notifications/Twilio: ese es parte del mismo pipeline que pudo
 * fallar. Se envía con `sendNow` (ver AlertPipelineFailure).
 *
 * Dos audiencias:
 * - `platform` (super-admins): todo el detalle técnico, con la excepción ya
 *   saneada por SafeException (nunca payload crudo ni secretos).
 * - `tenant` (owners/admins del team, sólo emergencias o alertas del
 *   proveedor sin clasificar): qué evento quedó sin procesar, sin detalles
 *   internos de SAM.
 *
 * @phpstan-type FailureDetails array{kind: string, stage: string, team_id: ?int, team_name: ?string, raw_event_id: ?int, normalized_event_id: ?int, event_type_code: ?string, external_event_type?: ?string, is_emergency: bool, asset_id: ?int, asset_name: ?string, occurred_at: ?string, failed_at: string, error_class: ?string, error_message: ?string, reprocess_attempts: ?int}
 */
class PipelineFailureNotification extends Notification
{
    use Queueable;

    public const string AUDIENCE_PLATFORM = 'platform';

    public const string AUDIENCE_TENANT = 'tenant';

    /**
     * @param  FailureDetails  $details
     */
    public function __construct(
        public readonly string $audience,
        public readonly array $details,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $d = $this->details;
        $unmappedAlert = $d['kind'] === PipelineFailureAlert::KIND_UNMAPPED_ALERT;
        $webhookMissed = $d['kind'] === PipelineFailureAlert::KIND_WEBHOOK_MISSED;
        $what = match (true) {
            $webhookMissed => 'Webhook sin entregar una emergencia',
            $unmappedAlert => 'Alerta del proveedor sin clasificar',
            $d['is_emergency'] => 'EMERGENCIA sin procesar',
            default => 'Fallo del pipeline',
        };
        $type = $unmappedAlert
            ? ($d['external_event_type'] ?? 'alerta')
            : ($d['event_type_code'] ?? 'evento');

        $mail = (new MailMessage)
            ->error()
            ->subject("[SAM] {$what}: {$type}".($d['team_name'] !== null ? " · {$d['team_name']}" : ''))
            ->line(match (true) {
                $webhookMissed => 'El respaldo de SAM encontró en el proveedor una emergencia (p. ej. un botón de pánico) que el webhook nunca entregó. El incidente ya se abrió por el respaldo, pero el webhook de la integración no está funcionando: revisa la Secret Key y el estado del webhook en Integraciones.',
                $unmappedAlert => 'Llegó una alerta del proveedor (posible emergencia, p. ej. un botón de pánico) que SAM no pudo clasificar, así que no se abrió incidente. Revísala de inmediato en la plataforma del proveedor y confirma con la unidad.',
                $d['is_emergency'] => 'Un evento de emergencia no pudo procesarse automáticamente. Revísalo de inmediato en la plataforma y confirma con la unidad.',
                default => 'Un evento no pudo procesarse automáticamente.',
            })
            ->line('Tenant: '.($d['team_name'] ?? 'sin tenant resoluble'))
            ->line('Tipo: '.$type)
            ->line('Activo: '.($d['asset_name'] ?? ($d['asset_id'] !== null ? "#{$d['asset_id']}" : 'sin resolver')))
            ->line('Hora del evento: '.($d['occurred_at'] ?? 'desconocida'))
            ->line('Hora del fallo: '.$d['failed_at'])
            ->line('Raw event: '.($d['raw_event_id'] ?? '—').' · Evento normalizado: '.($d['normalized_event_id'] ?? '—'));

        if ($this->audience === self::AUDIENCE_PLATFORM) {
            $mail->line('Etapa: '.$d['stage'].' ('.$d['kind'].')');

            if ($d['reprocess_attempts'] !== null) {
                $mail->line("Rescates agotados: {$d['reprocess_attempts']}");
            }

            if ($d['error_class'] !== null) {
                $mail->line('Error: '.$d['error_class'].' — '.($d['error_message'] ?? ''));
            }
        }

        return $mail;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $data = ['audience' => $this->audience, ...$this->details];

        if ($this->audience === self::AUDIENCE_TENANT) {
            unset($data['error_class'], $data['error_message'], $data['stage'], $data['kind'], $data['reprocess_attempts']);
        }

        return $data;
    }
}
