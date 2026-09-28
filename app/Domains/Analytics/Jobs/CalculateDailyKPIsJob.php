<?php

namespace App\Domains\Analytics\Jobs;

use App\Domains\Analytics\Actions\CalculateKPIsForTenant;
use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CalculateDailyKPIsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Above the supervisor default, below the `redis` retry_after (240 s). */
    public int $timeout = 220;

    /** @var array<int, int> */
    public array $backoff = [5, 30, 90];

    public function __construct(
        public ?int $teamId = null,
        public ?string $forDate = null,
    ) {
        $this->onQueue('analytics');
    }

    public function handle(CalculateKPIsForTenant $action): void
    {
        $day = $this->forDate ? now()->parse($this->forDate) : now()->subDay();
        $start = $day->copy()->startOfDay();
        $end = $day->copy()->endOfDay();

        $teamsQuery = Team::query()
            ->whereHas('teamSubscription', function ($query) {
                $query->withoutGlobalScopes()
                    ->whereIn('status', [
                        SubscriptionStatus::Active->value,
                        SubscriptionStatus::PastDue->value,
                    ]);
            });

        if ($this->teamId === null) {
            // Scheduled run (no team): fan out one job per tenant, like the
            // monthly invoicing. One job for every tenant grows past its timeout
            // with the customer base, and a retry restarted everyone from zero.
            $teamsQuery->select('teams.id')->chunkById(100, function ($teams) use ($day) {
                foreach ($teams as $team) {
                    self::dispatch((int) $team->id, $day->toDateString());
                }
            });

            return;
        }

        if ($teamsQuery->whereKey($this->teamId)->exists()) {
            $action->execute($this->teamId, $start, $end);
        }
    }
}
