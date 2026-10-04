<?php

namespace Tests\Feature\Domains\Notifications\Drivers;

use App\Domains\Notifications\Channels\WebPushMessenger;
use App\Domains\Notifications\Data\WebPushTarget;
use Minishlink\WebPush\VAPID;
use RuntimeException;
use Tests\TestCase;

class WebPushMessengerTest extends TestCase
{
    private function configureVapid(): void
    {
        $keys = VAPID::createVapidKeys();
        config(['webpush.vapid' => ['subject' => 'mailto:soporte@example.com', 'public_key' => $keys['publicKey'], 'private_key' => $keys['privateKey']]]);
    }

    public function test_is_not_configured_without_vapid_keys(): void
    {
        config(['webpush.vapid' => ['subject' => null, 'public_key' => null, 'private_key' => null]]);

        $this->assertFalse(app(WebPushMessenger::class)->isConfigured());
    }

    public function test_is_configured_with_all_three_values(): void
    {
        $this->configureVapid();

        $this->assertTrue(app(WebPushMessenger::class)->isConfigured());
    }

    public function test_send_refuses_without_configuration(): void
    {
        config(['webpush.vapid' => ['subject' => null, 'public_key' => null, 'private_key' => null]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('webpush_not_configured');

        app(WebPushMessenger::class)->send(
            [new WebPushTarget(1, 'https://example.com/push/1', 'pk', 'auth', 'aes128gcm')],
            '{}',
            60,
            'high',
        );
    }

    public function test_send_with_no_targets_returns_no_outcomes(): void
    {
        $this->configureVapid();

        $this->assertSame([], app(WebPushMessenger::class)->send([], '{}', 60, 'high'));
    }

    public function test_unreachable_endpoint_yields_failed_outcome_without_leaking_the_endpoint(): void
    {
        $this->configureVapid();

        $outcomes = app(WebPushMessenger::class)->send(
            [new WebPushTarget(
                7,
                'https://127.0.0.1:9/push/abc',
                'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM',
                'tBHItJI5svbpez7KI4CCXg',
                'aes128gcm',
            )],
            '{"title":"x"}',
            60,
            'high',
        );

        $this->assertCount(1, $outcomes);
        $this->assertSame(7, $outcomes[0]->subscriptionId);
        $this->assertFalse($outcomes[0]->success);
        $this->assertFalse($outcomes[0]->expired);
        $this->assertNotNull($outcomes[0]->reason);
        $this->assertStringNotContainsString('127.0.0.1', (string) $outcomes[0]->reason);
        $this->assertStringNotContainsString('/push/abc', (string) $outcomes[0]->reason);
    }
}
