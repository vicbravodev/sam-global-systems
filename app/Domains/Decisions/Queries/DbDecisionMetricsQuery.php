<?php

namespace App\Domains\Decisions\Queries;

use App\Contracts\Decisions\DecisionMetricsQuery;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Decisions\Models\DecisionOverride;
use App\Support\TenantContext;
use Carbon\CarbonInterface;

class DbDecisionMetricsQuery implements DecisionMetricsQuery
{
    /**
     * La consulta recibe el tenant por parámetro y puede llamarse desde un job
     * de plataforma, así que entra en ese tenant en vez de filtrar a mano: el
     * scope global hace el resto y no depende del contexto que hubiera. §2.1.
     */
    public function totalsForTenant(int $teamId, CarbonInterface $from, CarbonInterface $to): array
    {
        return TenantContext::for($teamId, function () use ($from, $to) {
            $base = Decision::query()->whereBetween('decided_at', [$from, $to]);

            $total = (int) (clone $base)->count();
            $humanReviewed = (int) (clone $base)->where('requires_human_review', true)->count();
            $autoResolved = (int) (clone $base)->where('is_automated', true)->count();

            $humanOverrides = (int) DecisionOverride::query()
                ->whereIn(
                    'decision_id',
                    Decision::query()
                        ->whereBetween('decided_at', [$from, $to])
                        ->select('id'),
                )
                ->count();

            return [
                'total' => $total,
                'human_reviewed' => $humanReviewed,
                'human_overrides' => $humanOverrides,
                'auto_resolved' => $autoResolved,
            ];
        });
    }
}
