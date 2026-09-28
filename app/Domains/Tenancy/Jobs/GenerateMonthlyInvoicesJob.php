<?php

namespace App\Domains\Tenancy\Jobs;

use App\Models\Team;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

/**
 * Monthly invoicing run (1st of the month): closes the PREVIOUS month for every
 * tenant with an operational subscription and generates its draft invoice.
 *
 * The daily AggregateUsageJob only maintains the running month, so usage that
 * landed after its last run of the month (the final hours of the last day)
 * would be missing from the counters. Each tenant therefore gets a chain:
 * recompute the closed month's counters from usage_events, then invoice them.
 */
class GenerateMonthlyInvoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('billing');
    }

    public function handle(): void
    {
        $periodStart = now()->subMonthNoOverflow()->startOfMonth();
        $start = $periodStart->toDateString();
        $end = $periodStart->endOfMonth()->toDateString();

        // Platform run across tenants on purpose (`teamSubscription` only
        // matches active/past_due); each tenant's chain is dispatched
        // inside its own context. Ver CLAUDE.md §2.1.
        TenantContext::withoutTenant(fn () => Team::query()
            ->whereHas('teamSubscription')
            ->chunkById(100, function ($teams) use ($start, $end) {
                foreach ($teams as $team) {
                    TenantContext::for($team->id, fn () => Bus::chain([
                        new AggregateUsageJob($team->id, $start),
                        new GenerateInvoiceSnapshotJob($team->id, $start, $end),
                    ])->onQueue('billing')->dispatch());
                }
            }));
    }
}
