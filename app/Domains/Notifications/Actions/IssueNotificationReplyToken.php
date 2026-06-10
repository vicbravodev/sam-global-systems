<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\Notifications\Models\NotificationReplyToken;

/**
 * Short-lived reply token correlating an outbound SMS/WhatsApp with the
 * incident it announces (Roadmap B9): the operator answers "SI-<token>" /
 * "NO-<token>" / "ESC-<token>" and the inbound webhook maps it back to the
 * incident and the user.
 */
class IssueNotificationReplyToken
{
    public const TTL_HOURS = 24;

    public function execute(
        Notification $notification,
        NotificationRecipient $recipient,
        ChannelType $channelType,
        int $incidentId,
    ): NotificationReplyToken {
        $existing = NotificationReplyToken::withoutGlobalScopes()
            ->where('team_id', $notification->team_id)
            ->where('incident_id', $incidentId)
            ->where('address', $recipient->address)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return NotificationReplyToken::create([
            'team_id' => $notification->team_id,
            'incident_id' => $incidentId,
            'notification_id' => $notification->id,
            'user_id' => is_numeric($recipient->recipient_reference_id)
                ? (int) $recipient->recipient_reference_id
                : null,
            'channel_type' => $channelType,
            'address' => $recipient->address,
            'token' => $this->uniqueToken(),
            'expires_at' => now()->addHours(self::TTL_HOURS),
        ]);
    }

    private function uniqueToken(): string
    {
        do {
            // 4 chars from an unambiguous alphabet (no 0/O/1/I/L) — short
            // enough to type back from a phone, unique-checked against the table.
            $token = '';
            $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

            for ($i = 0; $i < 4; $i++) {
                $token .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (NotificationReplyToken::withoutGlobalScopes()->where('token', $token)->exists());

        return $token;
    }
}
