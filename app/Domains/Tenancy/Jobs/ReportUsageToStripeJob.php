<?php

namespace App\Domains\Tenancy\Jobs;

use App\Domains\Tenancy\Models\TenantUsageCounter;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReportUsageToStripeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [1, 5, 10];

    public function __construct(
        public int $teamId,
    ) {
        $this->onQueue('billing');
    }

    public function handle(): void
    {
        $team = Team::findOrFail($this->teamId);

        $billableMeters = UsageMeter::whereNotNull('provider_meter_event_name')
            ->where('is_billable', true)
            ->get();

        foreach ($billableMeters as $meter) {
            $counter = TenantUsageCounter::withoutGlobalScopes()
                ->where('team_id', $team->id)
                ->where('usage_meter_id', $meter->id)
                ->whereDate('period_start', now()->startOfMonth()->toDateString())
                ->first();

            if (! $counter || $counter->consumed_value <= 0) {
                continue;
            }

            try {
                $team->reportMeterEvent(
                    $meter->provider_meter_event_name,
                    $counter->consumed_value,
                );
            } catch (\Throwable $e) {
                Log::error('Failed to report usage to Stripe', [
                    'team_id' => $team->id,
                    'meter_code' => $meter->code,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }
    }
}
