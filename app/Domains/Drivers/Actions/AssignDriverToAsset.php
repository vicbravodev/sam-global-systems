<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Drivers\Enums\AssignmentSource;
use App\Domains\Drivers\Enums\AssignmentType;
use App\Domains\Drivers\Events\DriverAssigned;
use App\Domains\Drivers\Models\DriverAssignment;

class AssignDriverToAsset
{
    public function execute(
        int $teamId,
        int $driverId,
        int $assetId,
        AssignmentType $assignmentType,
        AssignmentSource $source,
        ?\DateTimeInterface $startedAt = null,
        ?string $sourceReferenceId = null,
        ?array $metadata = null,
    ): DriverAssignment {
        $startedAt = $startedAt ?? now();

        if ($assignmentType === AssignmentType::PrimaryDriver) {
            $this->endActivePrimaryAssignment($assetId);
        }

        $assignment = DriverAssignment::create([
            'team_id' => $teamId,
            'driver_id' => $driverId,
            'asset_id' => $assetId,
            'assignment_type' => $assignmentType,
            'started_at' => $startedAt,
            'source' => $source,
            'source_reference_id' => $sourceReferenceId,
            'metadata_json' => $metadata,
        ]);

        DriverAssigned::dispatch(
            $teamId,
            $driverId,
            $assetId,
            $assignmentType->value,
            $startedAt,
        );

        return $assignment;
    }

    private function endActivePrimaryAssignment(int $assetId): void
    {
        DriverAssignment::where('asset_id', $assetId)
            ->where('assignment_type', AssignmentType::PrimaryDriver)
            ->whereNull('ended_at')
            ->update(['ended_at' => now()]);
    }
}
