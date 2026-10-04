<?php

namespace App\Domains\Integrations\Data;

use Illuminate\Support\Arr;

/**
 * One driver's row of Samsara `GET /fleet/hos/clocks`, normalized to seconds.
 *
 * `dutyStatus` keeps Samsara's raw value (`driving`, `onDuty`, `offDuty`,
 * `sleeperBed`, `yardMove`, `personalConveyance`); Samsara returns an empty
 * string when the driver app is disconnected, mapped here to `null`. A clock
 * missing from the payload is `null` (unknown), never a default.
 */
final readonly class HosClockReading
{
    public function __construct(
        public string $externalDriverId,
        public ?string $externalVehicleId,
        public ?string $dutyStatus,
        public ?int $breakRemainingSeconds,
        public ?int $driveRemainingSeconds,
        public ?int $shiftRemainingSeconds,
        public ?int $cycleRemainingSeconds,
        public int $violationSeconds,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromSamsara(array $row): ?self
    {
        $driverId = Arr::get($row, 'driver.id');

        if (! is_scalar($driverId) || (string) $driverId === '') {
            return null;
        }

        $vehicleId = Arr::get($row, 'currentVehicle.id');
        $status = Arr::get($row, 'currentDutyStatus.hosStatusType');

        return new self(
            externalDriverId: (string) $driverId,
            externalVehicleId: is_scalar($vehicleId) && (string) $vehicleId !== '' ? (string) $vehicleId : null,
            dutyStatus: is_string($status) && $status !== '' ? $status : null,
            breakRemainingSeconds: self::seconds(Arr::get($row, 'clocks.break.timeUntilBreakDurationMs')),
            driveRemainingSeconds: self::seconds(Arr::get($row, 'clocks.drive.driveRemainingDurationMs')),
            shiftRemainingSeconds: self::seconds(Arr::get($row, 'clocks.shift.shiftRemainingDurationMs')),
            cycleRemainingSeconds: self::seconds(Arr::get($row, 'clocks.cycle.cycleRemainingDurationMs')),
            violationSeconds: (self::seconds(Arr::get($row, 'violations.shiftDrivingViolationDurationMs')) ?? 0)
                + (self::seconds(Arr::get($row, 'violations.cycleViolationDurationMs')) ?? 0),
        );
    }

    /**
     * @return array{external_driver_id: string, external_vehicle_id: string|null, duty_status: string|null, break_remaining_s: int|null, drive_remaining_s: int|null, shift_remaining_s: int|null, cycle_remaining_s: int|null, violation_s: int}
     */
    public function toArray(): array
    {
        return [
            'external_driver_id' => $this->externalDriverId,
            'external_vehicle_id' => $this->externalVehicleId,
            'duty_status' => $this->dutyStatus,
            'break_remaining_s' => $this->breakRemainingSeconds,
            'drive_remaining_s' => $this->driveRemainingSeconds,
            'shift_remaining_s' => $this->shiftRemainingSeconds,
            'cycle_remaining_s' => $this->cycleRemainingSeconds,
            'violation_s' => $this->violationSeconds,
        ];
    }

    /**
     * @param  array<string, mixed>  $data  shape of {@see toArray()}
     */
    public static function fromArray(array $data): self
    {
        return new self(
            externalDriverId: (string) $data['external_driver_id'],
            externalVehicleId: isset($data['external_vehicle_id']) ? (string) $data['external_vehicle_id'] : null,
            dutyStatus: isset($data['duty_status']) ? (string) $data['duty_status'] : null,
            breakRemainingSeconds: isset($data['break_remaining_s']) ? (int) $data['break_remaining_s'] : null,
            driveRemainingSeconds: isset($data['drive_remaining_s']) ? (int) $data['drive_remaining_s'] : null,
            shiftRemainingSeconds: isset($data['shift_remaining_s']) ? (int) $data['shift_remaining_s'] : null,
            cycleRemainingSeconds: isset($data['cycle_remaining_s']) ? (int) $data['cycle_remaining_s'] : null,
            violationSeconds: (int) ($data['violation_s'] ?? 0),
        );
    }

    private static function seconds(mixed $milliseconds): ?int
    {
        return is_numeric($milliseconds) ? intdiv((int) $milliseconds, 1000) : null;
    }
}
