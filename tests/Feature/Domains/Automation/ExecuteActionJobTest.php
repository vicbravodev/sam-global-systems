<?php

namespace Tests\Feature\Domains\Automation;

use App\Domains\Automation\Actions\ExecuteAction;
use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Jobs\ExecuteActionJob;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Models\User;
use Database\Seeders\NotificationMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class ExecuteActionJobTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    public function test_handle_completes_pending_execution(): void
    {
        Mail::fake();
        $this->seed(NotificationMeterSeeder::class);

        $user = User::factory()->create();

        NotificationChannel::factory()->email()->create();

        $execution = ActionExecution::factory()->create([
            'team_id' => $user->currentTeam->id,
            'action_type' => ActionType::SendEmail,
            'status' => ActionExecutionStatus::Queued,
            'target_type' => 'email',
            'target_reference' => 'ops@example.test',
        ]);

        (new ExecuteActionJob($execution->id))->handle(app(ExecuteAction::class));

        $execution->refresh();

        $this->assertSame(ActionExecutionStatus::Completed, $execution->status);
        $this->assertSame(1, $execution->logs()->count());
    }

    public function test_handle_skips_completed_execution(): void
    {
        $user = User::factory()->create();

        $execution = ActionExecution::factory()->create([
            'team_id' => $user->currentTeam->id,
            'action_type' => ActionType::SendEmail,
            'status' => ActionExecutionStatus::Completed,
            'attempts' => 1,
        ]);

        (new ExecuteActionJob($execution->id))->handle(app(ExecuteAction::class));

        $execution->refresh();

        $this->assertSame(1, $execution->attempts);

        $this->assertSystemLogged('automation.action.skipped', fn (array $c) => $c['reason'] === 'already_completed'
            && $c['input']['action_execution_id'] === $execution->id);
        $this->assertSystemNotLogged('automation.action.completed');
    }

    public function test_handle_skips_cancelled_execution(): void
    {
        $user = User::factory()->create();

        $execution = ActionExecution::factory()->create([
            'team_id' => $user->currentTeam->id,
            'action_type' => ActionType::SendEmail,
            'status' => ActionExecutionStatus::Cancelled,
        ]);

        (new ExecuteActionJob($execution->id))->handle(app(ExecuteAction::class));

        $execution->refresh();

        $this->assertSame(ActionExecutionStatus::Cancelled, $execution->status);
        $this->assertSame(0, $execution->logs()->count());

        $this->assertSystemLogged('automation.action.skipped', fn (array $c) => $c['reason'] === 'already_cancelled'
            && $c['input']['action_execution_id'] === $execution->id);
    }

    public function test_handle_no_ops_when_execution_missing(): void
    {
        (new ExecuteActionJob(999_999))->handle(app(ExecuteAction::class));

        $this->assertSame(0, ActionExecution::withoutGlobalScopes()->count());

        $this->assertSystemLogged('automation.action.skipped', fn (array $c) => $c['reason'] === 'execution_missing'
            && $c['input'] === ['action_execution_id' => 999_999]);
        $this->assertNoSensitiveDataLogged();
    }
}
