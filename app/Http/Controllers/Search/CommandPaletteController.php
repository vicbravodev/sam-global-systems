<?php

namespace App\Http\Controllers\Search;

use App\Domains\Assets\Models\Asset;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Support\IncidentStatusPresenter;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Search backend for the command palette: incidents, fleet units (name,
 * code, plate, VIN) and drivers (name, employee code, phone) of the current
 * tenant. Read-only and intentionally small (top 8 incidents, top 5 of the
 * rest) — the palette is a jump-to, not a report. Each group is only
 * searched when the user's role may open that resource.
 */
class CommandPaletteController extends Controller
{
    private const INCIDENT_LIMIT = 8;

    private const ENTITY_LIMIT = 5;

    public function __invoke(Request $request, Team $current_team): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        return response()->json([
            'incidents' => Gate::allows('viewAny', Incident::class)
                ? $this->incidents($current_team, $query)
                : [],
            'assets' => $query !== '' && Gate::allows('viewAny', Asset::class)
                ? $this->assets($current_team, $query)
                : [],
            'drivers' => $query !== '' && Gate::allows('viewAny', Driver::class)
                ? $this->drivers($current_team, $query)
                : [],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function incidents(Team $team, string $query): array
    {
        // "INC-00036" / "36": the per-tenant number, never the global id.
        $number = preg_match('/^(?:inc-?)?0*(\d{1,9})$/i', $query, $matches) === 1 ? (int) $matches[1] : null;

        return array_values(Incident::query()
            ->where('team_id', $team->id)
            ->with(['priority', 'status', 'currentAssignment'])
            ->when($query !== '', function ($builder) use ($query, $number) {
                $builder->where(function ($q) use ($query, $number) {
                    $q->where('title', 'like', "%{$query}%")
                        ->when($number !== null, fn ($inner) => $inner->orWhere('number', $number));
                });
            })
            ->orderByDesc('id')
            ->limit(self::INCIDENT_LIMIT)
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
            ->all());
    }

    /**
     * @return list<array{id: int, name: string, code: string|null, plate: string|null}>
     */
    private function assets(Team $team, string $query): array
    {
        $term = $this->likeTerm($query);

        return array_values(Asset::query()
            ->where('team_id', $team->id)
            ->where(fn (Builder $q) => $q
                ->whereLike('name', $term)
                ->orWhereLike('code', $term)
                ->orWhereLike('metadata_json->license_plate', $term)
                ->orWhereLike('metadata_json->vin', $term))
            ->orderBy('name')
            ->limit(self::ENTITY_LIMIT)
            ->get(['id', 'name', 'code', 'metadata_json'])
            ->map(fn (Asset $asset): array => [
                'id' => (int) $asset->id,
                'name' => (string) $asset->name,
                'code' => $asset->code,
                'plate' => is_scalar($asset->metadata_json['license_plate'] ?? null)
                    ? (string) $asset->metadata_json['license_plate']
                    : null,
            ])
            ->all());
    }

    /**
     * @return list<array{id: int, name: string, employeeCode: string|null}>
     */
    private function drivers(Team $team, string $query): array
    {
        $term = $this->likeTerm($query);

        return array_values(Driver::query()
            ->where('team_id', $team->id)
            ->where(fn (Builder $q) => $q
                ->whereLike('full_name', $term)
                ->orWhereLike('employee_code', $term)
                ->orWhereLike('phone', $term))
            ->orderBy('full_name')
            ->limit(self::ENTITY_LIMIT)
            ->get(['id', 'full_name', 'employee_code'])
            ->map(fn (Driver $driver): array => [
                'id' => (int) $driver->id,
                'name' => (string) $driver->full_name,
                'employeeCode' => $driver->employee_code,
            ])
            ->all());
    }

    /**
     * Case-insensitive `%term%` with the user's wildcards escaped.
     */
    private function likeTerm(string $query): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $query).'%';
    }
}
