<?php

namespace App\Http\Controllers\Analytics;

use App\Domains\Analytics\Enums\SnapshotType;
use App\Domains\Analytics\Models\AnalyticsSnapshot;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AnalyticsSnapshotController extends Controller
{
    public function show(Team $current_team, string $type): JsonResponse
    {
        $this->authorize('viewAny', AnalyticsSnapshot::class);

        $snapshotType = SnapshotType::tryFrom($type);

        if (! $snapshotType) {
            throw new NotFoundHttpException("Unknown snapshot type [{$type}]");
        }

        $snapshot = AnalyticsSnapshot::query()
            ->where('snapshot_type', $snapshotType->value)
            ->orderByDesc('period_start')
            ->first();

        if (! $snapshot) {
            return response()->json(['data' => null], 404);
        }

        return response()->json(['data' => $snapshot]);
    }
}
