<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Audit\Support\AuditActionPresenter;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cross-tenant audit viewer for the SaaS operator. Surfaces the security- and
 * billing-category events (impersonation, plan/member/feature changes, tenant
 * lifecycle, operator changes) across every tenant.
 */
class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $showSystem = $request->boolean('system');

        // Bitácora de la consola de operador: cruza tenants a propósito.
        $logs = TenantContext::withoutTenant(fn () => AuditLog::query()
            ->whereIn('category', [AuditCategory::Security, AuditCategory::Billing])
            ->when(! $showSystem, fn ($q) => $q->whereNotIn('action', AuditActionPresenter::NOISE_ACTIONS))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(150)
            ->get());

        $teamNames = Team::withTrashed()
            ->whereIn('id', $logs->pluck('team_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        $entries = $logs->map(fn (AuditLog $log) => [
            'id' => $log->id,
            'action' => $log->action,
            'actionLabel' => AuditActionPresenter::actionLabel($log->action),
            // La consulta filtra por categoría Security/Billing: nunca es null aquí.
            'category' => (string) $log->category?->value,
            'categoryLabel' => AuditActionPresenter::categoryLabel($log->category),
            'summary' => $log->summary,
            // team_id es un id de secuencia (FK a teams): 0 es imposible.
            'team' => $log->team_id !== null ? ($teamNames[$log->team_id] ?? "Tenant eliminado #{$log->team_id}") : null,
            'actorEmail' => ($log->metadata_json ?? [])['actor_email'] ?? null,
            'occurredAt' => $log->occurred_at?->toIso8601String(),
        ])->values()->all();

        return Inertia::render('admin/audit/index', [
            'entries' => $entries,
            'filters' => ['system' => $showSystem],
        ]);
    }
}
