<?php

namespace App\Domains\Notifications\Channels;

use App\Contracts\Notifications\NotificationDriver;
use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Models\NotificationChannel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Slack incoming-webhook driver.
 *
 * Required config_json keys:
 *   - slack_webhook_url — Slack incoming webhook URL (cifrado at rest).
 *
 * Optional:
 *   - timeout       — request timeout in seconds (default 10).
 *   - default_channel — Slack channel override.
 *   - incident_url_template — sprintf-style template, e.g. "https://app.example.com/incidents/%s",
 *                             used to build the "View incident" button URL when variables include
 *                             `incident_id`.
 *
 * For Critical-priority notifications we send Slack Blocks with a contextual
 * action button when an incident_id is present. Otherwise we POST a flat
 * Markdown message.
 */
class SlackNotificationDriver implements NotificationDriver
{
    public const DEFAULT_TIMEOUT_SECONDS = 10;

    public function send(RenderedNotification $notification, NotificationChannel $channel): DeliveryResult
    {
        $config = $channel->config_json ?? [];

        $webhookUrl = $config['slack_webhook_url'] ?? null;

        if (! is_string($webhookUrl) || $webhookUrl === '') {
            return DeliveryResult::failure('slack_webhook_url missing');
        }

        $timeout = is_int($config['timeout'] ?? null) ? (int) $config['timeout'] : self::DEFAULT_TIMEOUT_SECONDS;

        $payload = $this->buildPayload($notification, $config);

        try {
            $response = Http::timeout($timeout)
                ->asJson()
                ->post($webhookUrl, $payload);
        } catch (ConnectionException $e) {
            return DeliveryResult::failure('slack connection error: '.$e->getMessage(), [
                'driver' => 'slack',
            ]);
        } catch (\Throwable $e) {
            return DeliveryResult::failure('slack error: '.$e->getMessage(), [
                'driver' => 'slack',
            ]);
        }

        $responseBody = Str::limit((string) $response->body(), 500, '');
        $trimmed = trim($responseBody);

        $responsePayload = [
            'driver' => 'slack',
            'status_code' => $response->status(),
            'body' => $responseBody,
        ];

        // Slack incoming webhooks return 200 + body "ok" on success. We also
        // tolerate 2xx with an empty body for Slack-compatible relays.
        $isOk = $response->successful() && ($trimmed === 'ok' || $trimmed === '');

        if (! $isOk) {
            return DeliveryResult::failure(
                'slack returned HTTP '.$response->status().' '.$responseBody,
                $responsePayload,
            );
        }

        return DeliveryResult::success(
            providerMessageId: 'slack-'.(string) Str::uuid(),
            response: $responsePayload,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function buildPayload(RenderedNotification $notification, array $config): array
    {
        $priority = $notification->variables['priority'] ?? null;
        $isCritical = $priority === NotificationPriority::Critical->value
            || $priority === NotificationPriority::Critical;
        $incidentId = $notification->variables['incident_id'] ?? null;
        $incidentUrlTemplate = $config['incident_url_template'] ?? null;

        $subject = $notification->subject ?? '';
        $body = $notification->body;

        $base = [
            'text' => $this->slackEscape(($subject !== '' ? $subject.' — ' : '').$body),
        ];

        if (isset($config['default_channel']) && is_string($config['default_channel'])) {
            $base['channel'] = $config['default_channel'];
        }

        if (! $isCritical) {
            return $base;
        }

        $blocks = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => Str::limit($subject !== '' ? $subject : 'Critical notification', 150, ''),
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => $this->slackEscape($body),
                ],
            ],
        ];

        if (is_string($incidentUrlTemplate) && $incidentId !== null && $incidentId !== '') {
            $url = sprintf($incidentUrlTemplate, $incidentId);
            $blocks[] = [
                'type' => 'actions',
                'elements' => [
                    [
                        'type' => 'button',
                        'text' => [
                            'type' => 'plain_text',
                            'text' => 'View incident',
                        ],
                        'url' => $url,
                        'style' => 'danger',
                    ],
                ],
            ];
        }

        return array_merge($base, ['blocks' => $blocks]);
    }

    /**
     * Slack mrkdwn requires escaping `<`, `>`, `&`. We additionally escape the
     * common emphasis markers (`*`, `_`, `~`, backtick) so user-provided
     * subjects/bodies cannot accidentally break formatting.
     */
    private function slackEscape(string $text): string
    {
        $text = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);

        return strtr($text, [
            '*' => '\\*',
            '_' => '\\_',
            '~' => '\\~',
            '`' => '\\`',
        ]);
    }
}
