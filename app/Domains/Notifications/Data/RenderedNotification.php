<?php

namespace App\Domains\Notifications\Data;

use App\Domains\Notifications\Enums\ChannelType;

class RenderedNotification
{
    /**
     * @param  array<string, mixed>  $variables
     */
    public function __construct(
        public readonly ChannelType $channelType,
        public readonly string $address,
        public readonly ?string $subject,
        public readonly string $body,
        public readonly array $variables = [],
        public readonly ?string $recipientName = null,
        public readonly ?int $deliveryId = null,
        public readonly bool $acknowledgesIncident = false,
    ) {}

    /**
     * La misma entrega, sabiendo a qué fila pertenece y si quien la recibe
     * puede atender con ella un incidente (p. ej. una llamada de escalación
     * con "presiona 1"). Lo fija AttemptDelivery justo antes del driver.
     */
    public function forDelivery(int $deliveryId, bool $acknowledgesIncident): self
    {
        return new self(
            channelType: $this->channelType,
            address: $this->address,
            subject: $this->subject,
            body: $this->body,
            variables: $this->variables,
            recipientName: $this->recipientName,
            deliveryId: $deliveryId,
            acknowledgesIncident: $acknowledgesIncident,
        );
    }
}
