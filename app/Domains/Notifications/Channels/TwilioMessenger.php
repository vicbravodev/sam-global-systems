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

        return $this->factory->make()->messages->getContext($sid)->fetch();
    }

    /**
     * Mensajes de `from` a `to` creados desde `$since`, del más reciente al
     * más viejo. El número de plataforma es compartido: quien los use debe
     * descartar los que ya pertenecen a otro envío.
     *
     * @return list<object>
     */
    public function findRecentMessages(string $to, string $from, \DateTimeInterface $since): array
    {
        if (TwilioSandbox::enabled()) {
            return [];
        }

        $found = [];

        foreach ($this->factory->make()->messages->read(['to' => $to, 'from' => $from], 20) as $resource) {
            if ($resource->dateCreated instanceof \DateTimeInterface && $resource->dateCreated >= $since) {
                $found[] = $resource;
            }
        }

        return $found;
    }
}
