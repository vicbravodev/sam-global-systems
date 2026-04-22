<?php

namespace App\Domains\Tenancy\Actions;

use DateTimeInterface;

class RegisterUsageEvent
{
    public function __construct(
        private RecordUsageEvent $recordUsageEvent,
    ) {}

    public function execute(
        int $teamId,
        string $meterCode,
        int $quantity,
        string $eventKey,
        ?array $metadata = null,
        ?DateTimeInterface $occurredAt = null,
    ): void {
        $this->recordUsageEvent->execute(
            $teamId,
            $meterCode,
            $quantity,
            $eventKey,
            $metadata,
            $occurredAt,
        );
    }
}
