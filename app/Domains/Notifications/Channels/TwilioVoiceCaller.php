<?php

namespace App\Domains\Notifications\Channels;

use App\Domains\Notifications\Support\TwilioSandbox;

/**
 * Thin wrapper around the Twilio Voice API so callers (the voice driver, the
 * incident verification jobs and the charge reconciler) can be tested by
 * mocking THIS class — not the SDK's internals.
 *
 * In sandbox mode ({@see TwilioSandbox}) no request leaves the app.
 */
class TwilioVoiceCaller
{
    public function __construct(
        private readonly TwilioClientFactory $factory,
    ) {}

    /**
     * @param  array<string, mixed>  $params  Twilio `calls->create` params (`twiml`, `statusCallback`, `statusCallbackEvent`, `timeout`, ...).
     * @return object Twilio CallInstance with at least `sid` and `status` properties.
     */
    public function createCall(string $to, string $from, array $params): object
    {
        if (TwilioSandbox::enabled()) {
            return TwilioSandbox::createCall($to);
        }

        return $this->factory->make()->calls->create($to, $from, $params);
    }

    /**
     * @return object Twilio CallInstance: `status`, `duration`, `price`, `priceUnit`.
     */
    public function fetchCall(string $sid): object
    {
        if (TwilioSandbox::enabled()) {
            return TwilioSandbox::fetchCall($sid);
        }

        return $this->factory->make()->calls->getContext($sid)->fetch();
    }
}
