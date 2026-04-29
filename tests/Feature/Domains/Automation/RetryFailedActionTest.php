<?php

namespace Tests\Feature\Domains\Automation;

use App\Domains\Automation\Actions\RetryFailedAction;
use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Jobs\ExecuteActionJob;
use App\Domains\Automation\Models\ActionExecution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class RetryFailedActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_requeues_failed_action_when_attempts_remain(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $execution = ActionExecution::factory()
            ->failed()
            ->create([
                'team_id' => $user->currentTeam->id,
                'attempts' => 1,
            ]);

        $result = app(RetryFailedAction::class)->execute($execution);

        $this->assertTrue($result);
        $this->assertSame(ActionExecutionStatus::Retrying, $execution->fresh()->status);

        Bus::assertDispatched(ExecuteActionJob::class);
    }

    public function test_returns_false_when_retry_budget_exhausted(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $execution = ActionExecution::factory()
            ->failed()
            ->create([
                'team_id' => $user->currentTeam->id,
                'attempts' => 3,
            ]);

        $result = app(RetryFailedAction::class)->execute($execution);

        $this->assertFalse($result);
        $this->assertSame(ActionExecutionStatus::Failed, $execution->fresh()->status);

        Bus::assertNotDispatched(ExecuteActionJob::class);
    }
}
