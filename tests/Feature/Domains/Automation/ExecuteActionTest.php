<?php

namespace Tests\Feature\Domains\Automation;

use App\Domains\Automation\Actions\ExecuteAction;
use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Enums\ActionLogType;
use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Events\ActionExecuted;
use App\Domains\Automation\Events\ActionFailed;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Automation\Models\ActionTemplate;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Models\User;
use Database\Seeders\NotificationMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ExecuteActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_email_action_records_completed_status_and_log(): void
    {
        Event::fake([ActionExecuted::class]);
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

        $result = app(ExecuteAction::class)->execute($execution);

        $this->assertSame(ActionExecutionStatus::Completed, $result->status);
        $this->assertNotNull($result->executed_at);
        $this->assertSame(1, $result->attempts);
        $this->assertSame(1, $result->logs()->count());
        $this->assertSame(ActionLogType::Info, $result->logs()->first()->log_type);
        $this->assertNotNull($result->response_json['notification_id'] ?? null);

        Event::assertDispatched(ActionExecuted::class);
    }

    public function test_call_webhook_action_posts_payload_and_stores_response(): void
    {
        Http::fake([
            'example.test/*' => Http::response(['ok' => true], 200),
        ]);

        $user = User::factory()->create();

        $template = ActionTemplate::factory()
            ->webhook('https://example.test/hook')
            ->create(['team_id' => $user->currentTeam->id]);

        $execution = ActionExecution::factory()->create([
            'team_id' => $user->currentTeam->id,
            'action_type' => ActionType::CallWebhook,
            'status' => ActionExecutionStatus::Queued,
            'action_template_id' => $template->id,
            'payload_json' => ['event' => 'incident.created', 'id' => 42],
        ]);

        $result = app(ExecuteAction::class)->execute($execution);

        $this->assertSame(ActionExecutionStatus::Completed, $result->status);
        $this->assertSame(['ok' => true], $result->response_json['body']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://example.test/hook'
                && $request['event'] === 'incident.created'
                && $request['id'] === 42;
        });
    }

    public function test_failed_webhook_marks_execution_failed_and_dispatches_event(): void
    {
        Event::fake([ActionFailed::class]);

        Http::fake([
            'example.test/*' => Http::response(['error' => 'oops'], 500),
        ]);

        $user = User::factory()->create();

        $template = ActionTemplate::factory()
            ->webhook('https://example.test/hook')
            ->create(['team_id' => $user->currentTeam->id]);

        $execution = ActionExecution::factory()->create([
            'team_id' => $user->currentTeam->id,
            'action_type' => ActionType::CallWebhook,
            'status' => ActionExecutionStatus::Queued,
            'action_template_id' => $template->id,
        ]);

        $result = app(ExecuteAction::class)->execute($execution);

        $this->assertSame(ActionExecutionStatus::Failed, $result->status);
        $this->assertNotNull($result->error_message);
        $this->assertSame(ActionLogType::Error, $result->logs()->first()->log_type);

        Event::assertDispatched(ActionFailed::class);
    }
}
