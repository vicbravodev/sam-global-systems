<?php

namespace Tests\Unit\Domains\Drivers;

use App\Domains\Drivers\Data\HosDetection;
use App\Domains\Drivers\Enums\HosEpisodeResolution;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Drivers\Support\HosSituationDetector;
use App\Domains\Integrations\Data\HosClockReading;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class HosSituationDetectorTest extends TestCase
{
    private function config(array $overrides = []): HosMonitoringConfig
    {
        $defaults = require __DIR__.'/../../../../config/hos.php';

        return HosMonitoringConfig::fromArray($overrides, $defaults['defaults']);
    }

    private function reading(?string $status, ?int $break = 28800, ?int $drive = 39600, ?int $shift = 50400, ?int $cycle = 252000, int $violation = 0): HosClockReading
    {
        return new HosClockReading('1', '100', $status, $break, $drive, $shift, $cycle, $violation);
    }

    private function detect(?HosClockReading $previous, HosClockReading $current, array $open = [], array $config = []): HosDetection
    {
        return (new HosSituationDetector)->detect($previous, $current, $this->config($config), $open, CarbonImmutable::parse('2026-10-04 12:00:00'));
    }

    public function test_driving_near_the_break_opens_break_due(): void
    {
        $result = $this->detect(null, $this->reading('driving', break: 26 * 60));

        $this->assertSame([HosSituation::BreakDue], $result->open);
    }

    public function test_driving_far_from_every_limit_opens_nothing(): void
    {
        $this->assertSame([], $this->detect(null, $this->reading('driving', break: 3 * 3600))->open);
    }

    public function test_break_due_resolves_only_when_the_break_clock_resets(): void
    {
        $open = ['break_due' => CarbonImmutable::parse('2026-10-04 11:50:00')];

        // Parado 5 min: el reloj no se ha reiniciado, el episodio sigue abierto.
        $this->assertSame([], $this->detect(null, $this->reading('offDuty', break: 600), $open)->resolve);

        $this->assertSame(
            ['break_due' => HosEpisodeResolution::Corrected],
            $this->detect(null, $this->reading('offDuty', break: 28800), $open)->resolve,
        );
    }

    public function test_an_open_situation_is_not_reopened(): void
    {
        $open = ['break_due' => CarbonImmutable::parse('2026-10-04 11:50:00')];

        $this->assertSame([], $this->detect(null, $this->reading('driving', break: 600), $open)->open);
    }

    public function test_drive_and_shift_limits(): void
    {
        $result = $this->detect(null, $this->reading('driving', drive: 20 * 60, shift: 25 * 60));

        $this->assertEqualsCanonicalizing([HosSituation::DriveLimit, HosSituation::ShiftLimit], $result->open);

        // En turno sin manejar: aplica la ventana de 14 h, no la de manejo.
        $this->assertSame([HosSituation::ShiftLimit], $this->detect(null, $this->reading('onDuty', drive: 20 * 60, shift: 25 * 60))->open);
    }

    public function test_cycle_limit_opens_and_resolves_on_reset(): void
    {
        $this->assertSame([HosSituation::CycleLimit], $this->detect(null, $this->reading('offDuty', cycle: 4 * 3600))->open);

        $open = ['cycle_limit' => CarbonImmutable::parse('2026-10-03 12:00:00')];
        $this->assertSame(['cycle_limit' => HosEpisodeResolution::Corrected], $this->detect(null, $this->reading('offDuty', cycle: 252000), $open)->resolve);
    }

    public function test_violation_opens_and_resolves(): void
    {
        $this->assertSame([HosSituation::Violation], $this->detect(null, $this->reading('driving', violation: 60))->open);
        $this->assertSame([HosSituation::Violation, HosSituation::DriveLimit], $this->detect(null, $this->reading('driving', drive: 0))->open);

        $open = ['violation' => CarbonImmutable::parse('2026-10-04 11:00:00')];
        $this->assertSame(['violation' => HosEpisodeResolution::Corrected], $this->detect(null, $this->reading('offDuty', drive: 0), $open)->resolve);
    }

    public function test_the_sleeping_partner_of_a_team_truck_opens_nothing(): void
    {
        // Equipo de dos choferes: el de sleeper con manejo/turno en 0 está cumpliendo su descanso.
        $this->assertSame([], $this->detect(null, $this->reading('sleeperBed', drive: 0, shift: 0))->open);
    }

    public function test_rest_complete_opens_on_the_reset_transition_only(): void
    {
        $previous = $this->reading('offDuty', break: 600);

        $this->assertSame([HosSituation::RestComplete], $this->detect($previous, $this->reading('offDuty', break: 28800))->open);
        // Sin "antes" no hay transición.
        $this->assertSame([], $this->detect(null, $this->reading('offDuty', break: 28800))->open);
        // Ya arrancó: no hay nada que recordar.
        $this->assertSame([], $this->detect($previous, $this->reading('driving', break: 28800))->open);
        // Descanso de 10 h cumplido (manejo vuelve a 11 h).
        $this->assertSame([HosSituation::RestComplete], $this->detect($this->reading('sleeperBed', break: 28800, drive: 0), $this->reading('sleeperBed', break: 28800, drive: 39600))->open);
    }

    public function test_rest_complete_does_not_open_when_the_driver_cannot_legally_drive(): void
    {
        $previous = $this->reading('offDuty', break: 600);

        // Pausa de 30 min cumplida pero sin ventana de 14 h: no hay que avisarle que arranque.
        $this->assertSame([], $this->detect($previous, $this->reading('offDuty', break: 28800, shift: 0))->open);
        $this->assertSame([], $this->detect($previous, $this->reading('offDuty', break: 28800, drive: 1800))->open);
        // (el ciclo bajo sí abre cycle_limit; lo que importa es que no haya fin de pausa)
        $this->assertNotContains(HosSituation::RestComplete, $this->detect($previous, $this->reading('offDuty', break: 28800, cycle: 5 * 3600))->open);
        // Sin relojes no se puede saber: no se abre.
        $this->assertSame([], $this->detect($previous, $this->reading('offDuty', break: 28800, shift: null))->open);
        $this->assertSame([], $this->detect($previous, $this->reading('offDuty', break: 28800, drive: null))->open);
        $this->assertSame([], $this->detect($previous, $this->reading('offDuty', break: 28800, cycle: null))->open);
        // Con horas disponibles sí abre.
        $this->assertSame([HosSituation::RestComplete], $this->detect($previous, $this->reading('offDuty', break: 28800, drive: 7200, shift: 9000, cycle: 30000))->open);
    }

    public function test_rest_complete_resolves_when_driving_or_expires(): void
    {
        $open = ['rest_complete' => CarbonImmutable::parse('2026-10-04 11:50:00')];

        $this->assertSame(['rest_complete' => HosEpisodeResolution::Corrected], $this->detect(null, $this->reading('driving'), $open)->resolve);
        $this->assertSame([], $this->detect(null, $this->reading('offDuty'), $open)->resolve);

        $expired = ['rest_complete' => CarbonImmutable::parse('2026-10-04 11:20:00')];
        $this->assertSame(['rest_complete' => HosEpisodeResolution::Expired], $this->detect(null, $this->reading('offDuty'), $expired)->resolve);
    }

    public function test_disabled_situations_do_not_open(): void
    {
        $result = $this->detect(null, $this->reading('driving', break: 600), config: ['situations' => ['break_due' => false]]);

        $this->assertSame([], $result->open);
    }

    public function test_a_disconnected_app_freezes_everything(): void
    {
        $open = ['break_due' => CarbonImmutable::parse('2026-10-04 11:50:00')];
        $result = $this->detect(null, $this->reading(null, break: 28800, violation: 60), $open);

        $this->assertSame([], $result->open);
        $this->assertSame([], $result->resolve);
    }

    public function test_missing_clocks_never_open_a_situation(): void
    {
        $this->assertSame([], $this->detect(null, $this->reading('driving', break: null, drive: null, shift: null, cycle: null))->open);
    }
}
