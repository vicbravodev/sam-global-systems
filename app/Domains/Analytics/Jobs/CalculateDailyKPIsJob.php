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
                        SubscriptionStatus::Trialing->value,
                        SubscriptionStatus::Active->value,
                        SubscriptionStatus::PastDue->value,
                    ]);
            });

        if ($this->teamId) {
            $teamsQuery->where('id', $this->teamId);
        }

        $teamsQuery->chunkById(100, function ($teams) use ($action, $start, $end) {
            foreach ($teams as $team) {
                $action->execute($team->id, $start, $end);
            }
        });
    }
}
