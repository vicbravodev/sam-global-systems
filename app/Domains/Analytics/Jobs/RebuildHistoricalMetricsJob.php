<?php

namespace App\Domains\Analytics\Jobs;

use App\Domains\Analytics\Actions\CalculateKPIsForTenant;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RebuildHistoricalMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public int $teamId,
        public string $fromDate,
        public string $toDate,
    ) {
        $this->onQueue('analytics');
    }

    public function handle(CalculateKPIsForTenant $action): void
    {
        $cursor = Carbon::parse($this->fromDate)->startOfDay();
        $end = Carbon::parse($this->toDate)->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            $action->execute(
                $this->teamId,
                $cursor->copy()->startOfDay(),
                $cursor->copy()->endOfDay(),
            );
            $cursor->addDay();
        }
    }
}
