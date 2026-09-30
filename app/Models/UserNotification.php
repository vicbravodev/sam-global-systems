<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Notificación in-app (canal `database` de Laravel) de un usuario. La tabla
 * `notifications` es del dominio Notifications, de ahí `user_notifications`.
 *
 * `team_id` se toma de `data.team_id` de la notificación: con tenant activo,
 * un usuario sólo ve los avisos de ese tenant. Los avisos con audiencia
 * `platform` se guardan siempre sin tenant (`team_id` null) y sólo se ven sin
 * tenant activo (consola de super-admin).
 */
class UserNotification extends DatabaseNotification
{
    use BelongsToTenant;

    protected $table = 'user_notifications';

    protected static function booted(): void
    {
        static::creating(function (self $notification): void {
            $data = is_array($notification->data) ? $notification->data : [];

            // Un aviso de plataforma (detalle técnico para super-admins) nunca
            // es de un tenant: con tenant activo no debe verse, aunque el
            // super-admin sea además miembro de ese team.
            if (($data['audience'] ?? null) === 'platform') {
                $notification->team_id = null;

                return;
            }

            if ($notification->team_id === null && is_numeric($data['team_id'] ?? null)) {
                $notification->team_id = (int) $data['team_id'];
            }
        });
    }
}
