<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\Notifications\Models\NotificationTemplate;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;

class RenderNotificationContent
{
    public function execute(
        Notification $notification,
        NotificationRecipient $recipient,
        ChannelType $channelType,
        ?NotificationTemplate $template = null,
    ): RenderedNotification {
        $variables = $notification->payload_json ?? [];

        $template = $template ?? $this->resolveTemplate($notification, $channelType);

        $subject = $template?->subject_template
            ? $this->render($template->subject_template, $variables)
            : $notification->subject;

        $body = $template?->body_template
            ? $this->render($template->body_template, $variables)
            : ($notification->body_preview ?? '');

        return new RenderedNotification(
            channelType: $channelType,
            address: $recipient->address,
            subject: $subject,
            body: $body,
            variables: $variables,
            recipientName: $recipient->name,
        );
    }

    private function resolveTemplate(Notification $notification, ChannelType $channelType): ?NotificationTemplate
    {
        return NotificationTemplate::withoutGlobalScopes()
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
     * @param  array<string, mixed>  $variables
     */
    private function render(string $template, array $variables): string
    {
        try {
            return (string) Blade::render($template, $variables);
        } catch (\Throwable) {
            return $this->fallbackInterpolate($template, $variables);
        }
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function fallbackInterpolate(string $template, array $variables): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*\$?([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/',
            function (array $matches) use ($variables) {
                $key = $matches[1];
                $value = $variables[$key] ?? '';

                return is_scalar($value) ? (string) $value : Str::of(json_encode($value))->limit(200);
            },
            $template,
        );
    }
}
