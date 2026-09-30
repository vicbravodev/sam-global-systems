<?php

namespace App\Domains\Copilot\Data;

/**
 * Engine idle time over a window. `source` says how it was derived:
 * 'engine_state' (provider Idle state), 'ignition_speed' (ignition on while
 * GPS says stopped) or 'none' (no idle detected).
 */
final readonly class IdleSummary
{
    /**
     * @param  'engine_state'|'ignition_speed'|'none'  $source
     * @param  list<array{from: string, to: string, minutes: int}>  $segments
     */
    public function __construct(
        public float $hours,
        public string $source,
        public array $segments,
    ) {}
}
