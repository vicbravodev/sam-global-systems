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
use Tests\TestCase;

class ExecuteActionJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_completes_pending_execution(): void
    {
        Mail::fake();
        $this->seed(NotificationMeterSeeder::class);

        $user = User::factory()->create();

        NotificationChannel::factory()->email()->create([
            'team_id' => $user->currentTeam->id,
        ]);

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
    }

    public function test_handle_no_ops_when_execution_missing(): void
    {
        (new ExecuteActionJob(999_999))->handle(app(ExecuteAction::class));

        $this->assertSame(0, ActionExecution::withoutGlobalScopes()->count());
    }
}
