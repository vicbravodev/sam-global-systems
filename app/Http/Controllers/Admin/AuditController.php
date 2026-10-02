<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Audit\Support\AuditActionPresenter;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Collection;
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
    private const int PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $showSystem = $request->boolean('system');
        $requested = $request->query('category');
        $category = is_string($requested) && in_array($requested, [AuditCategory::Security->value, AuditCategory::Billing->value], true)
            ? AuditCategory::from($requested)
            : null;
        $tenantSlug = is_string($request->query('tenant')) && $request->query('tenant') !== '' ? $request->query('tenant') : null;
        $tenant = $tenantSlug !== null ? Team::withTrashed()->where('slug', $tenantSlug)->first() : null;
        $search = is_string($request->query('q')) ? trim($request->query('q')) : '';

        // Bitácora de la consola de operador: cruza tenants a propósito.
        $page = TenantContext::withoutTenant(fn () => AuditLog::query()
            ->whereIn('category', $category !== null ? [$category] : [AuditCategory::Security, AuditCategory::Billing])
            ->when(! $showSystem, fn ($q) => $q->whereNotIn('action', AuditActionPresenter::NOISE_ACTIONS))
            ->when($tenantSlug !== null, fn ($q) => $q->where('team_id', $tenant?->id ?? 0))
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner
                ->whereRaw('LOWER(summary) LIKE ?', ['%'.$this->likeEscape(mb_strtolower($search)).'%'])
                ->orWhereRaw('LOWER(action) LIKE ?', ['%'.$this->likeEscape(mb_strtolower($search)).'%'])))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString());

        /** @var Collection<int, AuditLog> $logs */
        $logs = $page->getCollection();

        $teamNames = Team::withTrashed()
            ->whereIn('id', $logs->pluck('team_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        // Entradas sin `actor_email` en metadata (facturas, canales): se
        // resuelve por actor_id para que el visor nunca muestre un actor vacío.
        $actorEmails = User::query()
            ->whereIn('id', $logs->where('actor_type', AuditActorType::User)->pluck('actor_id')->filter()->unique()->all())
            ->pluck('email', 'id');

        $entries = $logs->map(fn (AuditLog $log) => [
            'id' => $log->id,
            'action' => $log->action,
            'actionLabel' => AuditActionPresenter::actionLabel($log->action),
            // La consulta filtra por categoría Security/Billing: nunca es null aquí.
            'category' => (string) $log->category?->value,
            'categoryLabel' => AuditActionPresenter::categoryLabel($log->category),
            'summary' => $log->summary,
            // team_id es un id de secuencia (FK a teams): 0 es imposible.
            'team' => $log->team_id !== null ? ($teamNames[$log->team_id] ?? "Cliente eliminado #{$log->team_id}") : null,
            'actorEmail' => ($log->metadata_json ?? [])['actor_email']
                ?? ($log->actor_type === AuditActorType::User && $log->actor_id !== null ? ($actorEmails[$log->actor_id] ?? null) : null),
            'occurredAt' => $log->occurred_at?->toIso8601String(),
        ])->values()->all();

        return Inertia::render('admin/audit/index', [
            'entries' => $entries,
            'pagination' => [
                'page' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
            ],
            'filters' => [
                'system' => $showSystem,
                'category' => $category?->value,
                'tenant' => $tenantSlug,
                'q' => $search !== '' ? $search : null,
            ],
            'tenants' => fn () => Team::query()
                ->where('is_personal', false)
                ->orderBy('name')
                ->get(['name', 'slug'])
                ->map(fn (Team $team) => ['value' => $team->slug, 'label' => $team->name])
                ->values()->all(),
        ]);
    }

    private function likeEscape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
