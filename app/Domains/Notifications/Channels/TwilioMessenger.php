<?php

namespace App\Domains\Notifications\Channels;

use App\Domains\Notifications\Support\TwilioSandbox;

/**
 * Thin wrapper around the Twilio Messages API so SMS / WhatsApp drivers and
 * the charge reconciler can be tested by mocking THIS class — not the SDK's
 * internals (which use `__get` magic that does not play well with Mockery).
 *
 * In sandbox mode ({@see TwilioSandbox}) no request leaves the app.
 */
class TwilioMessenger
{
    public function __construct(
        private readonly TwilioClientFactory $factory,
    ) {}

    /**
     * @param  array<string, mixed>  $params  Twilio `messages->create` params (`from`, `body`, `contentSid`, `statusCallback`, ...).
     * @return object Twilio MessageInstance with at least `sid`, `status` and `numSegments`.
     */
    public function createMessage(string $to, array $params): object
    {
        if (TwilioSandbox::enabled()) {
            return TwilioSandbox::createMessage($to, $params);
        }

        return $this->factory->make()->messages->create($to, $params);
    }

    /**
     * @return object Twilio MessageInstance: `status`, `errorCode`, `numSegments`, `price`, `priceUnit`.
     */
    public function fetchMessage(string $sid): object
    {
        if (TwilioSandbox::enabled()) {
            return TwilioSandbox::fetchMessage($sid);
        }

        return $this->factory->make()->messages($sid)->fetch();
    }
}
