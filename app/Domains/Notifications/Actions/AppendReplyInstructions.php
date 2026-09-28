<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationRecipient;

/**
 * Critical incident SMS/WhatsApp carry a reply token (Roadmap B9) so the
 * operator can confirm/dismiss/escalate by answering the message. The SMS
 * body is pre-fitted so the driver's 160-char truncation never eats the
 * instructions. Shared by the first dispatch and the fallback channel; a
 * retry re-sends the stored payload, instructions included.
 */
class AppendReplyInstructions
{
    public function __construct(
        private readonly IssueNotificationReplyToken $issueReplyToken,
    ) {}

    public function execute(
        Notification $notification,
        NotificationRecipient $recipient,
        RenderedNotification $rendered,
    ): RenderedNotification {
        if (! in_array($rendered->channelType, [ChannelType::Sms, ChannelType::Whatsapp], true)) {
            return $rendered;
        }

        if ($notification->source_type !== NotificationSourceType::Incident
            || ! is_numeric($notification->source_reference_id)
            || ! $notification->priority->isCritical()) {
            return $rendered;
        }

        $token = $this->issueReplyToken->execute(
            $notification,
            $recipient,
            $rendered->channelType,
            (int) $notification->source_reference_id,
        );

        $instructions = "\nResponde SI-{$token->token} confirma / NO-{$token->token} descarta / ESC-{$token->token} escala";

        $body = $rendered->body;

        if ($rendered->channelType === ChannelType::Sms) {
            $maxBase = 160 - mb_strlen($instructions);

            if (mb_strlen($body) > $maxBase) {
                $body = mb_substr($body, 0, $maxBase - 1).'…';
            }
        }

        return new RenderedNotification(
            channelType: $rendered->channelType,
            address: $rendered->address,
            subject: $rendered->subject,
            body: $body.$instructions,
            variables: $rendered->variables,
            recipientName: $rendered->recipientName,
        );
    }
}
