<?php

namespace App\Domains\Notifications\Channels;

/**
 * Thin wrapper around the Twilio Voice API so callers (the voice driver and
 * the incident verification jobs) can be tested by mocking THIS class — not
 * the SDK's internals.
 */
class TwilioVoiceCaller
{
    public function __construct(
        private readonly TwilioClientFactory $factory,
    ) {}

    /**
     * @param  array<string, mixed>  $config  Channel `config_json`.
     * @param  array<string, mixed>  $params  Twilio `calls->create` params (`twiml`, `statusCallback`, `timeout`, ...).
     * @return object Twilio CallInstance with at least `sid` and `status` properties.
     */
    public function createCall(array $config, string $to, string $from, array $params): object
    {
        return $this->factory->make($config)->calls->create($to, $from, $params);
    }
}
