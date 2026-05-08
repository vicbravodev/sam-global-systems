<?php

namespace App\Domains\Notifications\Data;

/**
 * Provider-agnostic shape returned by FcmMessenger::sendMulticast. Keeps
 * PushNotificationDriver decoupled from the (final) Kreait DTOs so the
 * driver can be mocked cleanly in tests.
 */
class FcmSendReport
{
    /**
     * @param  list<string>  $invalidTokens  tokens FCM reported as unknown / invalid /
     *                                       unregistered, eligible for pruning.
     */
    public function __construct(
        public readonly int $successes,
        public readonly int $failures,
        public readonly array $invalidTokens = [],
    ) {}
}
