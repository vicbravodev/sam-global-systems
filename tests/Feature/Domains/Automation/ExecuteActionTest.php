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
use App\Domains\Tenancy\Models\Subscription;
use App\Models\User;
use Database\Seeders\NotificationMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\FakesHostResolution;
use Tests\TestCase;

class ExecuteActionTest extends TestCase
{
    use AssertsSystemLog, FakesHostResolution, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // OutboundUrlGuard resuelve el host antes de llamar al webhook.
        $this->fakeDns(['example.test' => ['93.184.216.34']]);
    }

    public function test_send_email_action_records_completed_status_and_log(): void
    {
        Event::fake([ActionExecuted::class]);
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

        $result = app(ExecuteAction::class)->execute($execution);

        $this->assertSame(ActionExecutionStatus::Completed, $result->status);
        $this->assertNotNull($result->executed_at);
        $this->assertSame(1, $result->attempts);
        $this->assertSame(1, $result->logs()->count());
        $this->assertSame(ActionLogType::Info, $result->logs()->first()->log_type);
        $this->assertNotNull($result->response_json['notification_id'] ?? null);

        Event::assertDispatched(ActionExecuted::class);

        $context = $this->assertSystemLogged('automation.action.completed', fn (array $c) => $c['input']['action_execution_id'] === $execution->id
            && $c['input']['action_type'] === ActionType::SendEmail->value
            && $c['calc']['attempt'] === 1
            && $c['result']['notification_id'] === $result->response_json['notification_id']
            && $c['result']['recipients_count'] === 1
            && isset($c['result']['notification_status']));
        $this->assertArrayHasKey('duration_ms', $context);
        $this->assertArrayNotHasKey('channel', $context['result']);
        $this->assertStringNotContainsString('ops@example.test', json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
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

        $context = $this->assertSystemLogged('automation.action.completed', fn (array $c) => $c['result'] === ['http_status' => 200]);
        $this->assertStringNotContainsString('example.test/hook', json_encode($context));
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
        $this->assertSame('Webhook returned status 500', $result->error_message);
        $this->assertSame('Webhook returned status 500', $result->logs()->first()->message);

        Event::assertDispatched(ActionFailed::class, fn (ActionFailed $event) => $event->errorMessage === 'Webhook returned status 500');

        $context = $this->assertSystemLogged('automation.action.failed', fn (array $c) => $c['reason'] === 'webhook_http_error'
            && $c['input']['action_execution_id'] === $execution->id
            && $c['calc']['attempt'] === 1
            && $c['result'] === ['error_class' => 'ActionFailure', 'http_status' => 500]);
        $this->assertArrayHasKey('duration_ms', $context);
        $this->assertArrayNotHasKey('error', $context);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_blocked_tenant_action_is_stopped_with_reason(): void
    {
        $team = User::factory()->create()->currentTeam;
        Subscription::factory()->suspended()->create(['team_id' => $team->id]);

        $execution = ActionExecution::factory()->create([
            'team_id' => $team->id,
            'action_type' => ActionType::SendEmail,
            'status' => ActionExecutionStatus::Queued,
            'target_type' => 'email',
            'target_reference' => 'ops@example.test',
        ]);

        $result = app(ExecuteAction::class)->execute($execution);

        $this->assertSame(ActionExecutionStatus::Cancelled, $result->status);
        $this->assertSystemLogged('automation.action.stopped', fn (array $c) => $c['reason'] === 'tenant_blocked'
            && $c['input']['action_execution_id'] === $execution->id
            && $c['calc'] === ['blocked_reason' => 'subscription_suspended']);
        $this->assertSystemNotLogged('automation.action.completed');
        $this->assertNoSensitiveDataLogged();
    }
}
