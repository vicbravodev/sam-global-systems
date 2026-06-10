<?php

namespace App\Http\Controllers\Audit;

use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Audit\Models\DomainEventLog;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tenant-facing audit page (Roadmap F14): the audit trail and the domain
 * event log, filtered server-side. The super-admin console keeps its own
 * cross-tenant view; this one is strictly team-scoped.
 */
class AuditPageController extends Controller
{
    private const PER_PAGE = 50;

    public function show(Request $request, Team $current_team): Response
    {
        $this->authorize('viewAny', AuditLog::class);

        $filters = [
            'q' => $request->filled('q') ? $request->string('q')->trim()->toString() : null,
            'category' => $request->filled('category')
                ? AuditCategory::tryFrom($request->string('category')->toString())?->value
                : null,
            'actor_type' => $request->filled('actor_type')
                ? AuditActorType::tryFrom($request->string('actor_type')->toString())?->value
                : null,
            'from' => $request->filled('from') ? $request->string('from')->toString() : null,
            'to' => $request->filled('to') ? $request->string('to')->toString() : null,
        ];

        $query = AuditLog::withoutGlobalScopes()
            ->where('team_id', $current_team->id);

        if ($filters['q'] !== null && $filters['q'] !== '') {
            $term = '%'.mb_strtolower(str_replace(['%', '_'], ['\%', '\_'], $filters['q'])).'%';
            $query->where(fn (Builder $q) => $q
                ->whereRaw('LOWER(action) LIKE ?', [$term])
                ->orWhereRaw('LOWER(entity_type) LIKE ?', [$term])
                ->orWhereRaw('LOWER(summary) LIKE ?', [$term]));
        }

        if ($filters['category'] !== null) {
            $query->where('category', $filters['category']);
        }

        if ($filters['actor_type'] !== null) {
            $query->where('actor_type', $filters['actor_type']);
        }

        if ($filters['from'] !== null) {
            $query->where('occurred_at', '>=', $filters['from']);
        }

        if ($filters['to'] !== null) {
            $query->where('occurred_at', '<=', $filters['to'].' 23:59:59');
        }

        $paginator = $query
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('audit/index', [
            'logs' => collect($paginator->items())
                ->map(fn (AuditLog $log): array => [
                    'id' => (int) $log->id,
                    'action' => $log->action,
                    'category' => $log->category?->value,
                    'actorType' => $log->actor_type?->value,
                    'actorId' => $log->actor_id !== null ? (int) $log->actor_id : null,
                    'entityType' => $log->entity_type,
                    'entityId' => $log->entity_id !== null ? (int) $log->entity_id : null,
                    'summary' => $log->summary,
                    'occurredAt' => $log->occurred_at?->toIso8601String(),
                ])
                ->all(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
            'filters' => $filters,
            'filterOptions' => fn (): array => [
                'categories' => array_map(fn (AuditCategory $category) => $category->value, AuditCategory::cases()),
                'actorTypes' => array_map(fn (AuditActorType $type) => $type->value, AuditActorType::cases()),
            ],
            'events' => fn () => DomainEventLog::withoutGlobalScopes()
                ->where('team_id', $current_team->id)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit(100)
                ->get()
                ->map(fn (DomainEventLog $event): array => [
                    'id' => (int) $event->id,
                    'eventName' => $event->event_name,
                    'aggregateType' => $event->aggregate_type,
                    'aggregateId' => $event->aggregate_id !== null ? (int) $event->aggregate_id : null,
                    'correlationId' => $event->correlation_id,
                    'occurredAt' => $event->occurred_at?->toIso8601String(),
                ])
                ->all(),
        ]);
    }
}
