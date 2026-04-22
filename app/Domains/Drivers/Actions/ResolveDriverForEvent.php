<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverAssignment;

class ResolveDriverForEvent
{
    public function execute(int $assetId, \DateTimeInterface $timestamp): ?Driver
    {
        $assignment = DriverAssignment::where('asset_id', $assetId)
            ->activeAt($timestamp)
            ->orderByDesc('started_at')
            ->first();

        if (! $assignment) {
            return null;
        }

        return Driver::withoutGlobalScopes()->find($assignment->driver_id);
    }
}
