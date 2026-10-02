<?php

namespace App\Domains\Incidents\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso a los super-admins de SAM de que la escalera de un incidente se agotó
 * sin que nadie del tenant lo atendiera. Va por Laravel Notifications directo
 * (correo + in-app), igual que PipelineFailureNotification: no por el dominio
 * Notifications/Twilio, que es justo lo que pudo no llegar a nadie.
 *
 * Sólo datos operativos (tenant, referencia, tipo, prioridad, niveles): nunca
 * contactos, teléfonos ni el texto del incidente.
 *
 * @phpstan-type ExhaustedDetails array{team_id: int, team_name: ?string, incident_id: int, incident_reference: string, incident_type: ?string, priority: ?string, levels_count: int, opened_at: ?string, exhausted_at: string}
 */
class EscalationExhaustedNotification extends Notification
{
    use Queueable;

    /**
     * @param  ExhaustedDetails  $details
     */
    public function __construct(public readonly array $details) {}

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

        return (new MailMessage)
            ->error()
            ->subject("[SAM] Escalación agotada sin atención: {$d['incident_reference']}".($d['team_name'] !== null ? " · {$d['team_name']}" : ''))
            ->line('Se avisó a todos los niveles de escalación del tenant y nadie atendió el incidente. Contacta al cliente.')
            ->line('Tenant: '.($d['team_name'] ?? "#{$d['team_id']}"))
            ->line("Incidente: {$d['incident_reference']} (#{$d['incident_id']})")
            ->line('Tipo: '.($d['incident_type'] ?? '—').' · Prioridad: '.($d['priority'] ?? '—'))
            ->line("Niveles avisados: {$d['levels_count']}")
            ->line('Abierto: '.($d['opened_at'] ?? '—').' · Agotado: '.$d['exhausted_at']);
    }

    /**
     * @return ExhaustedDetails
     */
    public function toArray(object $notifiable): array
    {
        return $this->details;
    }
}
