<?php

namespace Tests\Feature\Support;

use App\Domains\Automation\Actions\ExecuteAction;
use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Events\ActionFailed;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Automation\Models\ActionExecutionLog;
use App\Domains\Incidents\Events\IncidentStatusChanged;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Integrations\Actions\SyncIntegration;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Enums\SyncStatus;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\IntegrationSyncJob;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Notifications\Actions\DispatchNotification;
use App\Domains\Notifications\Jobs\SendNotificationJob;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Database\Seeders\NotificationMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * Los mensajes de excepciones ajenas no llegan a la DB ni a los eventos por
 * los caminos reales de fallo: entrega de notificación, sync de integración
 * y ejecución de automatización. Ver App\Support\SafeErrorMessage.
 */
class SafeErrorMessagePersistenceTest extends TestCase
{
    use RefreshDatabase;

    private const string SENSITIVE = 'POST https://hooks.example.com/services/T0/B0/xyzSecretPath?token=abc123 '
        .'failed for leak@secret.mx (+52 55 9876 5432) Bearer sk-live-abcdef';

    /** @var list<string> */
    private const array LEAKS = ['xyzSecretPath', 'abc123', 'leak@secret.mx', '9876 5432', 'sk-live-abcdef', 'hooks.example.com'];

    private function assertNoLeak(mixed $persisted): void
    {
        $json = (string) json_encode($persisted);

        foreach (self::LEAKS as $leak) {
            $this->assertStringNotContainsString($leak, $json);
        }
    }

    public function test_a_failed_notification_delivery_does_not_persist_the_raw_exception(): void
    {
        $this->seed(NotificationMeterSeeder::class);

        $user = User::factory()->create();
        $this->actingAs($user);

        $notification = Notification::factory()->create([
            'team_id' => $user->currentTeam->id,
            'payload_json' => [
                'recipients' => [
                    ['recipient_type' => 'external_contact', 'address' => 'ops@example.com'],
                ],
            ],
        ]);

        NotificationChannel::factory()->email()->create(['is_active' => true]);

        Mail::shouldReceive('to')->andThrow(new TransportException(self::SENSITIVE));

        (new SendNotificationJob($notification->id))->handle(app(DispatchNotification::class));

        $delivery = NotificationDelivery::withoutGlobalScopes()->where('notification_id', $notification->id)->sole();

        $this->assertSame('TransportException', $delivery->error_message);
        $this->assertNoLeak($delivery->getAttributes());
        $this->assertNoLeak(Notification::withoutGlobalScopes()->findOrFail($notification->id)->getAttributes());
    }

    public function test_a_failed_integration_sync_does_not_persist_the_raw_exception(): void
    {
        $team = User::factory()->create()->currentTeam;

        $integration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'provider_id' => IntegrationProvider::factory()->samsara()->create()->id,
            'name' => 'Sync Test',
            'auth_type' => 'api_key',
            'credentials_encrypted' => 'test-key',
            'status' => 'active',
        ]);

        $syncJob = IntegrationSyncJob::create([
            'tenant_integration_id' => $integration->id,
            'type' => 'full',
            'status' => SyncStatus::Pending,
        ]);

        $adapter = Mockery::mock(ProviderAdapter::class);
        $adapter->shouldReceive('sync')->once()->andThrow(new ConnectionException('cURL error 28: '.self::SENSITIVE));
        $this->app->instance(ProviderAdapter::class, $adapter);

        try {
            app(SyncIntegration::class)->execute($integration, $syncJob);
            $this->fail('The sync should re-throw the provider failure.');
        } catch (ConnectionException) {
            // Se re-lanza tras marcar el fallo.
        }

        $syncJob->refresh();
        $integration->refresh();

        $this->assertSame(SyncStatus::Failed, $syncJob->status);
        $this->assertSame('ConnectionException (cURL 28)', $syncJob->error_message);
        $this->assertSame('ConnectionException (cURL 28)', $integration->last_error_message);
        $this->assertNoLeak($syncJob->getAttributes());
        $this->assertNoLeak($integration->getAttributes());
    }

    public function test_a_failed_automation_execution_does_not_persist_nor_dispatch_the_raw_exception(): void
    {
        $this->seed(IncidentsSeeder::class);

        $team = User::factory()->create()->currentTeam;
        $incident = Incident::factory()->open()->create(['team_id' => $team->id]);

        Event::listen(IncidentStatusChanged::class, fn () => throw new RuntimeException(self::SENSITIVE));

        $dispatched = [];
        Event::listen(ActionFailed::class, function (ActionFailed $event) use (&$dispatched): void {
            $dispatched[] = $event->errorMessage;
        });

        $execution = ActionExecution::factory()->create([
            'team_id' => $team->id,
            'action_type' => ActionType::Escalate,
            'status' => ActionExecutionStatus::Queued,
            'incident_id' => $incident->id,
            'payload_json' => ['reason' => 'SLA at risk'],
        ]);

        $result = app(ExecuteAction::class)->execute($execution);

        $this->assertSame(ActionExecutionStatus::Failed, $result->status);
        $this->assertSame('RuntimeException', $result->error_message);
        $this->assertSame(['RuntimeException'], $dispatched);
        $this->assertNoLeak(ActionExecution::withoutGlobalScopes()->findOrFail($execution->id)->getAttributes());
        $this->assertNoLeak(ActionExecutionLog::query()->where('action_execution_id', $execution->id)->get()->map->getAttributes()->all());
    }
}
