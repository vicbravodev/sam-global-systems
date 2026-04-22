<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Drivers\Enums\DriverStatus;
use App\Domains\Drivers\Enums\StatusSeverity;
use App\Domains\Drivers\Events\DriverStatusChanged;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverStatusLog;

class UpdateDriverStatus
{
    public function execute(
        Driver $driver,
        DriverStatus $newStatus,
        StatusSeverity $severity = StatusSeverity::Low,
        ?string $sourceEventId = null,
        ?array $metadata = null,
    ): Driver {
        $previousStatus = $driver->status;

        DriverStatusLog::where('driver_id', $driver->id)
            ->whereNull('effective_to')
            ->update(['effective_to' => now()]);

        DriverStatusLog::create([
            'driver_id' => $driver->id,
            'status_code' => $newStatus->value,
            'status_label' => $newStatus->name,
            'severity' => $severity,
            'effective_from' => now(),
            'source_event_id' => $sourceEventId,
            'metadata_json' => $metadata,
        ]);

        $driver->update(['status' => $newStatus]);

        DriverStatusChanged::dispatch(
            $driver->team_id,
            $driver->id,
            $previousStatus->value,
            $newStatus->value,
        );

        return $driver->fresh();
    }
}
