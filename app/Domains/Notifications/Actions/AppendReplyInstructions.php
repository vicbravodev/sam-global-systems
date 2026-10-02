<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\Notifications\Support\SmsText;

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
            || ! $this->expectsReply($notification)) {
            return $rendered;
        }

        $token = $this->issueReplyToken->execute(
            $notification,
            $recipient,
            $rendered->channelType,
            (int) $notification->source_reference_id,
            $rendered->address,
        );

        $instructions = "\nResponde SI-{$token->token} confirma / NO-{$token->token} descarta / ESC-{$token->token} escala";

        $body = $rendered->body;

        if ($rendered->channelType === ChannelType::Sms) {
            // Medido ya en GSM-7 (lo que el driver mandará): así las
            // instrucciones nunca se recortan ni el mensaje pasa de un segmento.
            $body = SmsText::gsm7($body);
            $maxBase = 160 - mb_strlen($instructions);

            if (mb_strlen($body) > $maxBase) {
                $body = mb_substr($body, 0, $maxBase - 3).'...';
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

    /**
     * Crítico, o un aviso de escalación (lleva `escalation_level`) de
     * cualquier prioridad: quien recibe una escalación debe poder atenderla
     * respondiendo, también en un incidente alto.
     */
    private function expectsReply(Notification $notification): bool
    {
        return $notification->priority->isCritical()
            || is_numeric($notification->payload_json['escalation_level'] ?? null);
    }
}
