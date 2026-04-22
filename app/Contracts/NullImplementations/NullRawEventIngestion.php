<?php

namespace App\Contracts\NullImplementations;

use App\Contracts\RawEventIngestion;

class NullRawEventIngestion implements RawEventIngestion
{
    public function ingest(int $teamId, string $source, string $eventType, array $payload): void
    {
        // No-op: will be replaced when the Ingestion domain is implemented.
    }
}
