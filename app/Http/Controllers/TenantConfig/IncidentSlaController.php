<?php

namespace App\Http\Controllers\TenantConfig;

use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\TenantConfig\Models\TenantIncidentSla;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tarea 7: pantalla de tiempos de respuesta (SLA) por prioridad de incidente.
 * Consume el catálogo global de `IncidentPriority` (valor de fábrica) y los
 * overrides por tenant en `TenantIncidentSla` (Tarea 6), que
 * `ResolveIncidentSla` usa en tiempo de ejecución para calcular la vigilancia
 * de cada incidente.
 */
class IncidentSlaController extends Controller
{
    public function index(Team $current_team): Response
    {
        $this->authorize('viewAny', TenantSetting::class);

        $overrides = TenantIncidentSla::withoutGlobalScopes()
            ->where('team_id', $current_team->id)
            ->pluck('sla_seconds', 'incident_priority_id');

        $priorities = IncidentPriority::query()
            ->orderBy('level')
            ->get()
            ->map(fn (IncidentPriority $priority): array => [
                'id' => $priority->id,
                'code' => $priority->code,
                'name' => $priority->name,
                'default_sla_seconds' => $priority->sla_seconds,
                'sla_seconds' => $overrides->has($priority->id) ? $overrides->get($priority->id) : null,
            ])
            ->all();

        return Inertia::render('tenant-config/slas', [
            'priorities' => $priorities,
        ]);
    }

    public function update(Request $request, Team $current_team): RedirectResponse
    {
        $this->authorize('update', TenantSetting::class);

        $data = $request->validate([
            'slas' => ['required', 'array'],
            'slas.*.incident_priority_id' => ['required', 'integer', 'exists:incident_priorities,id'],
            'slas.*.sla_seconds' => ['nullable', 'integer', 'min:30', 'max:86400'],
        ]);

        foreach ($data['slas'] as $row) {
            TenantIncidentSla::withoutGlobalScopes()->updateOrCreate(
                [
                    'team_id' => $current_team->id,
                    'incident_priority_id' => $row['incident_priority_id'],
                ],
                ['sla_seconds' => $row['sla_seconds'] ?? null],
            );
        }

        return back()->with('status', 'Tiempos de respuesta actualizados.');
    }
}
