<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\Notifications\Models\NotificationTemplate;
use App\Support\Templates\TemplateInterpolator;

class RenderNotificationContent
{
    public function __construct(
        private readonly TemplateInterpolator $interpolator,
    ) {}

    public function execute(
        Notification $notification,
        NotificationRecipient $recipient,
        ChannelType $channelType,
        ?NotificationTemplate $template = null,
        ?string $addressOverride = null,
    ): RenderedNotification {
        $variables = $notification->payload_json ?? [];

        $template = $template ?? $this->resolveTemplate($notification, $channelType);

        $subject = $template?->subject_template
            ? $this->render($template->subject_template, $variables)
            : $notification->subject;

        $body = $template?->body_template
            ? $this->render($template->body_template, $variables)
            : ($notification->body_preview ?? '');

        [$subject, $body] = $this->withLateNotice($channelType, $subject, $body, $variables);

        return new RenderedNotification(
            channelType: $channelType,
            address: $addressOverride ?? $recipient->address,
            subject: $subject,
            body: $body,
            variables: $variables,
            recipientName: $recipient->name,
        );
    }

    /**
     * Un evento que llegó/se procesó tarde lleva `late_notice` en el payload
     * (NotifyOnIncidentCreated). Se antepone al cuerpo en todos los canales
     * —también con plantillas del tenant, que no lo conocen— y se suma al
     * asunto; la voz lee la versión hablada (sin emoji) y no la repite en el
     * asunto, que también se lee en voz alta.
     *
     * @param  array<string, mixed>  $variables
     * @return array{0: string|null, 1: string}
     */
    private function withLateNotice(ChannelType $channelType, ?string $subject, string $body, array $variables): array
    {
        $notice = $variables['late_notice'] ?? null;

        if (! is_string($notice) || $notice === '') {
            return [$subject, $body];
        }

        if ($channelType === ChannelType::Voice) {
            $spoken = $variables['late_notice_spoken'] ?? null;
            $spoken = is_string($spoken) && $spoken !== '' ? $spoken : $notice;

            return [$subject, trim($spoken.' '.$body)];
        }

        $subject = $subject !== null && $subject !== '' ? "{$subject} · {$notice}" : $notice;

        return [$subject, $body !== '' ? "{$notice}\n\n{$body}" : $notice];
    }

    private function resolveTemplate(Notification $notification, ChannelType $channelType): ?NotificationTemplate
    {
        return NotificationTemplate::query()
            ->where(function ($query) use ($notification) {
                $query->where('team_id', $notification->team_id)
                    ->orWhereNull('team_id');
            })
            ->where('channel_type', $channelType->value)
            ->where('event_type', $notification->notification_type)
            ->where('is_active', true)
            ->orderByRaw('CASE WHEN team_id IS NULL THEN 1 ELSE 0 END')
            ->first();
    }

    /**
     * Las plantillas las edita el tenant: nunca se compilan con Blade (sería
     * ejecución de código). Sólo se interpolan variables; el correo escapa el
     * HTML al pintar el cuerpo (GenericNotificationMail).
     *
     * @param  array<string, mixed>  $variables
     */
    private function render(string $template, array $variables): string
    {
        return $this->interpolator->render($template, $variables);
    }
}
