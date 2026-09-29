<?php

namespace Tests\Feature\Domains\Automation;

use App\Domains\Automation\Actions\RetryFailedAction;
use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Jobs\ExecuteActionJob;
use App\Domains\Automation\Models\ActionExecution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class RetryFailedActionTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    public function test_requeues_failed_action_when_attempts_remain(): void
    {
        Bus::fake();
        $this->freezeTime();

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

        $c = $this->assertSystemLogged('automation.action.retry_scheduled', fn (array $c) => ! isset($c['reason'])
            && $c['input']['action_execution_id'] === $execution->id
            && $c['calc']['attempts'] === 1
            && $c['calc']['backoff_index'] === 1
            && $c['calc']['index_in_schedule'] === true
            && $c['result']['job_requested'] === true);

        // (schedule[attempts] ?? last(schedule)) ?: 0
        $schedule = $c['calc']['backoff_schedule_seconds'];
        $expected = ($schedule[$c['calc']['attempts']] ?? $schedule[array_key_last($schedule)]) ?: 0;
        $this->assertSame($expected, $c['result']['delay_seconds']);
        $this->assertSame(60, $c['result']['delay_seconds']);
        Bus::assertDispatched(ExecuteActionJob::class, fn (ExecuteActionJob $job) => $job->delay !== null
            && (int) now()->diffInSeconds($job->delay) === $expected);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_second_retry_uses_its_scheduled_delay(): void
    {
        Bus::fake();
        $this->freezeTime();

        $execution = ActionExecution::factory()->failed()->create([
            'team_id' => User::factory()->create()->currentTeam->id,
            'attempts' => 2,
        ]);

        $this->assertTrue(app(RetryFailedAction::class)->execute($execution));

        $c = $this->assertSystemLogged('automation.action.retry_scheduled');
        $schedule = $c['calc']['backoff_schedule_seconds'];
        $expected = ($schedule[$c['calc']['attempts']] ?? $schedule[array_key_last($schedule)]) ?: 0;
        $this->assertSame($expected, $c['result']['delay_seconds']);
        Bus::assertDispatched(ExecuteActionJob::class, fn (ExecuteActionJob $job) => (int) now()->diffInSeconds($job->delay) === $expected);
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

        $this->assertSystemLogged('automation.action.retry_scheduled', fn (array $c) => $c['reason'] === 'retries_exhausted'
            && $c['input'] === ['action_execution_id' => $execution->id]
            && $c['calc'] === ['attempts' => 3, 'max_retries' => 3]);
    }
}
