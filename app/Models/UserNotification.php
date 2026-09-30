<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Notificación in-app (canal `database` de Laravel) de un usuario. La tabla
 * `notifications` es del dominio Notifications, de ahí `user_notifications`.
 *
 * `team_id` se toma de `data.team_id` de la notificación: con tenant activo,
 * un usuario sólo ve los avisos de ese tenant; los avisos de plataforma
 * (`team_id` null) sólo se ven sin tenant (consola de super-admin).
 */
class UserNotification extends DatabaseNotification
{
    use BelongsToTenant;

    protected $table = 'user_notifications';

    protected static function booted(): void
    {
        static::creating(function (self $notification): void {
            $teamId = is_array($notification->data) ? ($notification->data['team_id'] ?? null) : null;

            if ($notification->team_id === null && is_numeric($teamId)) {
                $notification->team_id = (int) $teamId;
            }
        });
    }
}
