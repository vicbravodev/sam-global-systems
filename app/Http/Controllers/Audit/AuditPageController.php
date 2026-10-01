<?php

namespace App\Http\Controllers\Audit;

use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Audit\Models\DomainEventLog;
use App\Domains\Audit\Support\AuditActionPresenter;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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

    /**
     * Human-readable labels for entity types, keyed by either the FQCN's
     * class basename or the raw (short) entity_type string. Falls back to
     * Str::headline() for anything not listed here.
     *
     * @var array<string, string>
     */
    private const ENTITY_LABELS = [
        'NormalizedEvent' => 'Evento normalizado',
        'RawEvent' => 'Evento crudo',
        'AIEventEvaluation' => 'Evaluación IA',
        'EventContextSnapshot' => 'Contexto de evento',
        'UsageRecorded' => 'Uso registrado',
        'Incident' => 'Incidente',
        'Decision' => 'Decisión',
        'User' => 'Usuario',
        'Team' => 'Tenant',
        'Subscription' => 'Suscripción',
        'NotificationChannel' => 'Canal de notificación',
        'InvoiceSnapshot' => 'Factura',
        'incident' => 'Incidente',
        'notification_channel' => 'Canal de notificación',
        'invoice_snapshot' => 'Factura',
    ];

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
            // Automated bookkeeping (usage recorded, events normalized…) is
            // hidden unless the viewer opts in.
            'system' => $request->boolean('system'),
        ];

        $query = AuditLog::query()
            ->where('team_id', $current_team->id);

        if (! $filters['system']) {
            $query->whereNotIn('action', AuditActionPresenter::NOISE_ACTIONS);
        }

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

        $items = collect($paginator->items());

        // Human actor names: only the users on this page, and only if they
        // are members of this team (never another tenant's user).
        $userIds = $items
            ->filter(fn (AuditLog $log) => $log->actor_type === AuditActorType::User && $log->actor_id !== null)
            ->pluck('actor_id')
            ->unique()
            ->all();
        $actorNames = $userIds === []
            ? []
            : User::query()
                ->whereIn('id', $userIds)
                ->whereHas('teams', fn (Builder $q) => $q->where('teams.id', $current_team->id))
                ->pluck('name', 'id')
                ->all();

        return Inertia::render('audit/index', [
            'logs' => $items
                ->map(fn (AuditLog $log): array => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'actionLabel' => AuditActionPresenter::actionLabel($log->action),
                    'category' => $log->category?->value,
                    'categoryLabel' => AuditActionPresenter::categoryLabel($log->category),
                    'actorType' => $log->actor_type?->value,
                    'actorId' => $log->actor_id,
                    'actorLabel' => $this->actorLabel($log, $actorNames),
                    'entityType' => $log->entity_type,
                    // The tenant itself is shown by name, not "Tenant #1".
                    'entityId' => $log->entity_id !== null && ! $this->isTeamEntity($log->entity_type)
                        ? $log->entity_id
                        : null,
                    'entityLabel' => $this->isTeamEntity($log->entity_type)
                        ? $current_team->name
                        : $this->entityLabel($log->entity_type),
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
                'categories' => array_map(fn (AuditCategory $category): array => [
                    'value' => $category->value,
                    'label' => AuditActionPresenter::categoryLabel($category),
                ], AuditCategory::cases()),
                'actorTypes' => array_map(fn (AuditActorType $type): array => [
                    'value' => $type->value,
                    'label' => AuditActionPresenter::actorTypeLabel($type),
                ], AuditActorType::cases()),
            ],
            'events' => fn () => DomainEventLog::query()
                ->where('team_id', $current_team->id)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit(100)
                ->get()
                ->map(fn (DomainEventLog $event): array => [
                    'id' => $event->id,
                    'eventName' => $event->event_name,
                    'aggregateType' => $event->aggregate_type,
                    'aggregateId' => $event->aggregate_id,
                    'correlationId' => $event->correlation_id,
                    'occurredAt' => $event->occurred_at?->toIso8601String(),
                ])
                ->all(),
        ]);
    }

    /**
     * @param  array<int, string>  $actorNames
     */
    private function actorLabel(AuditLog $log, array $actorNames): ?string
    {
        if ($log->actor_type === AuditActorType::User && $log->actor_id !== null) {
            return $actorNames[$log->actor_id] ?? 'Usuario #'.$log->actor_id;
        }

        return AuditActionPresenter::actorTypeLabel($log->actor_type);
    }

    private function isTeamEntity(?string $type): bool
    {
        return $type !== null && in_array(class_basename($type), ['Team', 'team', 'tenant'], true);
    }

    private function entityLabel(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }

        $base = class_basename($type);

        return self::ENTITY_LABELS[$base] ?? self::ENTITY_LABELS[$type] ?? Str::headline($base);
    }
}
