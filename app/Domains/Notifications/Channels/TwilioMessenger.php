<?php

namespace App\Domains\Notifications\Channels;

/**
 * Thin wrapper around the Twilio SDK so SMS / WhatsApp drivers can be
 * tested by mocking THIS class — not the SDK's internals (which use
 * `__get` magic that does not play well with Mockery).
 *
 * Production builds the Twilio Client per-call from the channel config;
 * tests bind a mock against this class in the container.
 */
class TwilioMessenger
{
    public function __construct(
        private readonly TwilioClientFactory $factory,
    ) {}

    /**
     * @param  array<string, mixed>  $config  Channel `config_json`.
     * @param  array<string, mixed>  $params  Twilio `messages->create` params (`from`, `body`, `contentSid`, `contentVariables`, ...).
     * @return object Twilio MessageInstance with at least `sid` and `status` properties.
     */
    public function createMessage(array $config, string $to, array $params): object
    {
        return $this->factory->make($config)->messages->create($to, $params);
    }
}
