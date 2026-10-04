<?php

namespace Tests\Unit\Domains\Copilot;

use App\Domains\Copilot\Support\CopilotLocalTimes;
use PHPUnit\Framework\TestCase;

/**
 * The model gets every instant in the tenant's local time, never raw UTC.
 */
class CopilotLocalTimesTest extends TestCase
{
    public function test_converts_every_iso_instant_recursively_to_local_time(): void
    {
        $facts = CopilotLocalTimes::localize([
            'recorded_at' => '2026-09-30T18:39:11+00:00',
            'latest' => [
                ['occurredAt' => '2026-10-01T02:44:56Z', 'title' => 'Botón de pánico'],
                ['occurredAt' => '2026-10-01T02:44:56.123456+00:00'],
            ],
            'opened_at' => '2026-09-30T12:00:00-05:00',
        ], 'America/Mexico_City');

        $this->assertSame('2026-09-30 12:39', $facts['recorded_at']);
        $this->assertSame('2026-09-30 20:44', $facts['latest'][0]['occurredAt']);
        $this->assertSame('Botón de pánico', $facts['latest'][0]['title']);
        $this->assertSame('2026-09-30 20:44', $facts['latest'][1]['occurredAt']);
        $this->assertSame('2026-09-30 11:00', $facts['opened_at']);
    }

    public function test_leaves_everything_else_untouched(): void
    {
        $facts = [
            'period' => 'del 23/09 al 30/09',
            'date_only' => '2026-09-30',
            'code' => 'T-0524 USA 32LA3T',
            'speed_kph' => 87,
            'location' => null,
            'mentions' => 'abrió a las 2026-09-30T18:39:11+00:00 según el log',
        ];

        $this->assertSame($facts, CopilotLocalTimes::localize($facts, 'America/Mexico_City'));
    }
}
