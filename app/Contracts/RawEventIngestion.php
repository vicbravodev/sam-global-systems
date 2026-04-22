<?php

namespace App\Contracts;

interface RawEventIngestion
{
    /**
     * Ingest a raw event from an external source into the ingestion pipeline.
     *
     * @param  array<string, mixed>  $payload
     */
    public function ingest(int $teamId, string $source, string $eventType, array $payload): void;
}
