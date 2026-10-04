<?php

namespace App\Domains\Drivers\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Assets\Models\Asset;
use App\Domains\Drivers\Enums\HosDutyStatus;
use App\Domains\Integrations\Data\HosClockReading;
use Database\Factories\Domains\Drivers\HosDriverStateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Last HOS clock snapshot SAM observed for a monitored driver.
 */
class HosDriverState extends Model
{
    /** @use HasFactory<HosDriverStateFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'team_id',
        'driver_id',
        'asset_id',
        'duty_status',
        'status_since',
        'break_remaining_s',
        'drive_remaining_s',
        'shift_remaining_s',
        'cycle_remaining_s',
        'violation_s',
        'app_disconnected_since',
        'observed_at',
    ];

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * Lo que queda en cada reloj, en segundos (null = Samsara no lo mandó).
     *
     * @return array{break: int|null, drive: int|null, shift: int|null, cycle: int|null}
     */
    public function clockSnapshot(): array
    {
        return [
            'break' => $this->break_remaining_s,
            'drive' => $this->drive_remaining_s,
            'shift' => $this->shift_remaining_s,
            'cycle' => $this->cycle_remaining_s,
        ];
    }

    /** The stored snapshot as a reading, the "before" side of transition checks. */
    public function toReading(string $externalDriverId, ?string $externalVehicleId): HosClockReading
    {
        return new HosClockReading(
            externalDriverId: $externalDriverId,
            externalVehicleId: $externalVehicleId,
            dutyStatus: $this->duty_status?->value,
            breakRemainingSeconds: $this->break_remaining_s,
            driveRemainingSeconds: $this->drive_remaining_s,
            shiftRemainingSeconds: $this->shift_remaining_s,
            cycleRemainingSeconds: $this->cycle_remaining_s,
            violationSeconds: $this->violation_s,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duty_status' => HosDutyStatus::class,
            'status_since' => 'datetime',
            'break_remaining_s' => 'integer',
            'drive_remaining_s' => 'integer',
            'shift_remaining_s' => 'integer',
            'cycle_remaining_s' => 'integer',
            'violation_s' => 'integer',
            'app_disconnected_since' => 'datetime',
            'observed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): HosDriverStateFactory
    {
        return HosDriverStateFactory::new();
    }
}
