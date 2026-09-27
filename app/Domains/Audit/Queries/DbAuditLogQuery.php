<?php

namespace App\Domains\Audit\Queries;

use App\Contracts\Audit\AuditLogQuery;
use App\Domains\Audit\Models\AuditLog;
use App\Support\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class DbAuditLogQuery implements AuditLogQuery
{
    /**
     * @return Collection<int, array<string, mixed>>
     *
     * Recibe el tenant por parámetro y puede llamarse desde un job de
     * plataforma o desde la consola de super-admin, así que entra en ese
     * tenant en vez de depender del contexto que hubiera. Ver §2.1.
     */
    public function forTenant(int $teamId, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return TenantContext::for($teamId, fn () => AuditLog::query()
            ->whereBetween('occurred_at', [$from, $to])
            ->orderBy('occurred_at')
            ->get()
            ->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'team_id' => $log->team_id,
                'actor_type' => $log->actor_type?->value,
                'actor_id' => $log->actor_id,
                'action' => $log->action,
                'category' => $log->category?->value,
                'entity_type' => $log->entity_type,
                'entity_id' => $log->entity_id,
                'source_type' => $log->source_type,
                'source_reference_id' => $log->source_reference_id,
                'signature' => $log->signature,
                'summary' => $log->summary,
                'metadata_json' => $log->metadata_json,
                'occurred_at' => $log->occurred_at?->toIso8601String(),
            ])
            ->values());
    }
}
