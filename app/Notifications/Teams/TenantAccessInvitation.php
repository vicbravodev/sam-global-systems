<?php

namespace App\Notifications\Teams;

use App\Models\Team;
use App\Models\User;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Password;

/**
 * Correo de bienvenida para quien el super-admin dio de alta en un tenant.
 * Lleva un enlace de un solo uso (broker `onboarding`, 7 días) para definir
 * la contraseña; al usarlo se verifica el email y aterriza en su empresa.
 *
 * El token se crea al RENDERIZAR (en el worker), no al despachar: el texto
 * plano nunca viaja serializado en la cola, y crear uno nuevo invalida el
 * anterior (reenviar acceso deja vivo sólo el último enlace).
 */
class TenantAccessInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $teamId,
        public readonly ?int $invitedById = null,
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Si la empresa se borró o el usuario ya activó su cuenta antes de que
     * saliera el correo, el enlace ya no tiene sentido.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        $team = Team::query()->find($this->teamId);
        $reason = match (true) {
            $team === null => 'team_deleted',
            ! $notifiable instanceof User => 'not_a_user',
            $notifiable->email_verified_at !== null => 'already_activated',
            default => null,
        };

        if ($reason === null) {
            return true;
        }

        TenantContext::for($this->teamId, fn () => SystemLog::skipped('tenancy.access_link.skipped',
            reason: $reason,
            input: ['team_id' => $this->teamId, 'user_id' => $notifiable instanceof User ? $notifiable->id : null, 'channel' => $channel],
        ));

        return false;
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $notifiable */
        $team = Team::query()->findOrFail($this->teamId);
        $inviter = $this->invitedById !== null ? User::query()->find($this->invitedById) : null;

        /** @var PasswordBroker $broker */
        $broker = Password::broker('onboarding');
        $token = $broker->createToken($notifiable);

        $days = max(1, intdiv((int) config('auth.passwords.onboarding.expire'), 1440));

        TenantContext::for($this->teamId, fn () => SystemLog::ok('tenancy.access_link.sent',
            input: ['team_id' => $this->teamId, 'user_id' => $notifiable->id, 'invited_by' => $this->invitedById],
            result: ['expires_in_days' => $days],
        ));

        return (new MailMessage)
            ->subject("Tu acceso a SAM para {$team->name}")
            ->greeting("Hola, {$notifiable->name}")
            ->line($inviter !== null
                ? "{$inviter->name} te dio de alta en SAM Global Systems para operar la flota de {$team->name}."
                : "Te dimos de alta en SAM Global Systems para operar la flota de {$team->name}.")
            ->line('Para entrar, define tu contraseña con el botón de abajo. Tu correo quedará verificado.')
            ->action('Definir mi contraseña', route('onboarding.show', [
                'token' => $token,
                'email' => $notifiable->email,
                'team' => $team->slug,
            ]))
            ->line("El enlace es de un solo uso y vence en {$days} días. Si vence, pide a SAM que te reenvíe el acceso.")
            ->salutation('Equipo SAM Global Systems');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['team_id' => $this->teamId];
    }
}
