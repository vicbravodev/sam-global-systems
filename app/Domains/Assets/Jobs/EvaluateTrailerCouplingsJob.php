<?php

namespace App\Domains\Assets\Jobs;

use App\Domains\Assets\Actions\EvaluateTrailerCouplings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Re-judge a tenant's tractor–trailer couplings. Dispatched after each cycle
 * of the trailers feed; the action re-reads the whole window, so a skipped
 * run loses nothing and the next one catches up.
 */
class EvaluateTrailerCouplingsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 55;

    public int $uniqueFor = 60;

    public function __construct(public readonly int $teamId)
    {
        $this->onQueue('telematics');
    }

    public function handle(EvaluateTrailerCouplings $evaluate): void
    {
        $evaluate->execute($this->teamId);
    }

    public function uniqueId(): string
    {
        return "trailer-couplings:{$this->teamId}";
    }
}
