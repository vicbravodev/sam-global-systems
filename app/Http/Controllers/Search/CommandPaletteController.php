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
        // "INC-00036" / "36": the per-tenant number, never the global id.
        $number = preg_match('/^(?:inc-?)?0*(\d{1,9})$/i', $query, $matches) === 1 ? (int) $matches[1] : null;

        $incidents = Incident::query()
            ->where('team_id', $current_team->id)
            ->with(['priority', 'status', 'currentAssignment'])
            ->when($query !== '', function ($builder) use ($query, $number) {
                $builder->where(function ($q) use ($query, $number) {
                    $q->where('title', 'like', "%{$query}%")
                        ->when($number !== null, fn ($inner) => $inner->orWhere('number', $number));
                });
            })
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (Incident $incident): array => [
                'id' => (int) $incident->id,
                'reference' => $incident->reference(),
                'title' => (string) $incident->title,
                'severity' => $incident->priority?->code,
                'status' => $incident->status?->code,
                // Same rendered string as the inbox/detail/asset surfaces.
                'statusLabel' => IncidentStatusPresenter::labelForIncident($incident),
            ])
            ->all();

        return response()->json(['incidents' => $incidents]);
    }
}
