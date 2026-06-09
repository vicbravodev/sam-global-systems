<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Audit\Models\AuditLog;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cross-tenant audit viewer for the SaaS operator. Surfaces the security- and
 * billing-category events (impersonation, plan/member/feature changes, tenant
 * lifecycle, operator changes) across every tenant.
 */
class AuditController extends Controller
{
    public function index(): Response
    {
        $logs = AuditLog::withoutGlobalScopes()
            ->whereIn('category', [AuditCategory::Security, AuditCategory::Billing])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(150)
            ->get();

        $teamNames = Team::withTrashed()
            ->whereIn('id', $logs->pluck('team_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        $entries = $logs->map(fn (AuditLog $log) => [
            'id' => (int) $log->id,
            'action' => (string) $log->action,
            'category' => $log->category->value,
            'summary' => (string) $log->summary,
            'team' => $log->team_id ? ($teamNames[$log->team_id] ?? "#{$log->team_id}") : null,
            'actorEmail' => ($log->metadata_json ?? [])['actor_email'] ?? null,
            'occurredAt' => $log->occurred_at?->toIso8601String(),
        ])->values()->all();

        return Inertia::render('admin/audit/index', [
            'entries' => $entries,
        ]);
    }
}
