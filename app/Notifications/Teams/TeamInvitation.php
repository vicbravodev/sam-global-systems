<?php

namespace App\Notifications\Teams;

use App\Models\TeamInvitation as TeamInvitationModel;
use App\Support\SamMailMessage;
use App\Support\SystemLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use LogicException;

class TeamInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public TeamInvitationModel $invitation)
    {
        //
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Va en cola: si la empresa se borró (soft-delete) antes del envío, la
     * invitación ya no sirve y no se manda el correo.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        if ($this->invitation->team !== null) {
            return true;
        }

        SystemLog::skipped('notifications.team_invitation.skipped',
            reason: 'team_deleted',
            input: ['invitation_id' => $this->invitation->id, 'team_id' => $this->invitation->team_id, 'channel' => $channel],
        );

        return false;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): SamMailMessage
    {
        // shouldSend() descarta el team borrado; invited_by es FK NOT NULL con
        // cascade sobre users (sin soft-delete), así que el invitador existe.
        $team = $this->invitation->team
            ?? throw new LogicException("TeamInvitation {$this->invitation->id} sin team: shouldSend() debió descartarla.");
        $inviter = $this->invitation->inviter
            ?? throw new LogicException("TeamInvitation {$this->invitation->id} sin invitador.");

        return (new SamMailMessage)
            ->subject(__("You've been invited to join :teamName", ['teamName' => $team->name]))
            ->eyebrow('Invitación')
            ->greeting("Te esperan en {$team->name}")
            ->line(__(':inviterName has invited you to join the :teamName team.', [
                'inviterName' => $inviter->name,
                'teamName' => $team->name,
            ]))
            ->line('Desde SAM vas a poder seguir la flota en tiempo real, atender incidentes y recibir las alertas que importan.')
            ->details([
                'Empresa' => $team->name,
                'Te invita' => $inviter->name,
                'Rol' => $this->invitation->role->label(),
            ])
            ->action(__('Accept invitation'), route('invitations.show', $this->invitation))
            ->line('Si no esperabas esta invitación, puedes ignorar este correo.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'invitation_id' => $this->invitation->id,
            'team_id' => $this->invitation->team_id,
            'team_name' => $this->invitation->team?->name,
            'role' => $this->invitation->role->value,
        ];
    }
}
