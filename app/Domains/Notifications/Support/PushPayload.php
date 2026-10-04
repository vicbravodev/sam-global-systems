<?php

namespace App\Domains\Notifications\Support;

use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Models\Notification;
use App\Models\Team;

/**
 * Lo que el service worker (public/sw.js) recibe: título, cuerpo, a dónde
 * abrir y cómo agrupar. `tag` por incidente hace que cada nivel de la
 * escalera reemplace al anterior y `renotify` que vuelva a sonar/vibrar.
 * Los servicios de push aceptan ~4 KB: el cuerpo se recorta.
 */
final class PushPayload
{
    private const MAX_BODY = 1000;

    public static function build(Notification $notification, Team $team, string $subject, string $body): string
    {
        $incidentId = $notification->payload_json['incident_id'] ?? null;
        $hasIncident = is_int($incidentId) || (is_string($incidentId) && ctype_digit($incidentId));

        $url = $hasIncident
            ? route('incidents.show', ['current_team' => $team->slug, 'incident' => (int) $incidentId])
            : route('notifications.show', ['current_team' => $team->slug, 'notification' => $notification->id]);

        return (string) json_encode([
            'title' => $subject,
            'body' => mb_strimwidth($body, 0, self::MAX_BODY, '…'),
            'url' => $url,
            'tag' => $hasIncident ? 'incident-'.(int) $incidentId : 'notification-'.$notification->id,
            'critical' => $notification->priority === NotificationPriority::Critical,
            'renotify' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
