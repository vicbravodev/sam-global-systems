<?php

namespace App\Http\Controllers\Search;

use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Support\IncidentStatusPresenter;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Search backend for the command palette: most recent incidents of the
 * tenant, optionally filtered by title/id. Read-only and intentionally
 * small (top 8) — the palette is a jump-to, not a report.
 */
class CommandPaletteController extends Controller
{
    public function __invoke(Request $request, Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', Incident::class);

        $query = trim((string) $request->query('q', ''));

        $incidents = Incident::withoutGlobalScopes()
            ->where('team_id', $current_team->id)
            ->with(['priority', 'status', 'currentAssignment'])
            ->when($query !== '', function ($builder) use ($query) {
                $builder->where(function ($q) use ($query) {
                    $q->where('title', 'like', "%{$query}%")
                        ->orWhere('id', 'like', "%{$query}%");
                });
            })
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (Incident $incident): array => [
                'id' => (int) $incident->id,
                'title' => (string) $incident->title,
                'severity' => $incident->priority?->code,
                'status' => $incident->status?->code,
                // Same rendered string as the inbox/detail/asset surfaces.
                'statusLabel' => IncidentStatusPresenter::label(
                    $incident->status?->code,
                    $incident->currentAssignment !== null,
                ),
            ])
            ->all();

        return response()->json(['incidents' => $incidents]);
    }
}
