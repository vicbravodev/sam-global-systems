<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Automation\Actions\ExecuteAction;
use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Automation\Models\ActionTemplate;
use App\Domains\Notifications\Actions\RenderNotificationContent;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\Notifications\Models\NotificationTemplate;
use App\Models\User;
use Database\Seeders\NotificationMeterSeeder;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Las plantillas las edita el tenant: compilarlas con Blade era ejecución de
 * código arbitrario en el servidor ({{ }} y @php son PHP). Ahora sólo se
 * interpolan variables.
 */
class TemplateInjectionTest extends TestCase
{
    use RefreshDatabase;

    private const PAYLOAD = '{{ \App\Models\User::query()->update([\'name\' => \'pwned\']) }}@php \App\Models\User::query()->update([\'email\' => \'pwned@x.test\']); @endphp';

    public function test_notification_templates_do_not_execute_php(): void
    {
        $user = User::factory()->create(['name' => 'Original']);
        $team = $user->currentTeam;

        $template = NotificationTemplate::factory()->create([
            'team_id' => $team->id,
            'subject_template' => self::PAYLOAD,
            'body_template' => 'Hola {{ $name }} '.self::PAYLOAD,
        ]);
        $notification = Notification::factory()->create([
            'team_id' => $team->id,
            'payload_json' => ['name' => 'Ana'],
        ]);
        $recipient = NotificationRecipient::factory()->create([
            'notification_id' => $notification->id,
            'team_id' => $team->id,
        ]);

        $rendered = app(RenderNotificationContent::class)->execute($notification, $recipient, ChannelType::Sms, $template);

        $this->assertStringStartsWith('Hola Ana ', $rendered->body);
        $this->assertStringContainsString('@php', $rendered->body);
        $this->assertSame('Original', $user->fresh()->name);
        $this->assertNotSame('pwned@x.test', $user->fresh()->email);
    }

    public function test_seeded_global_templates_render_as_before(): void
    {
        $this->seed(NotificationTemplateSeeder::class);

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $payload = [
            'incident_title' => 'Botón de pánico',
            'asset_name' => 'T-7',
            'driver_name' => 'Ana',
            'location' => 'Av. Juárez',
            'has_media' => 'sí',
            'incident_url' => 'https://app.test/i/1',
        ];

        foreach (NotificationTemplate::query()->whereNull('team_id')->get() as $template) {
            $notification = Notification::factory()->create(['team_id' => $team->id, 'payload_json' => $payload]);
            $recipient = NotificationRecipient::factory()->create(['notification_id' => $notification->id, 'team_id' => $team->id]);

            $rendered = app(RenderNotificationContent::class)->execute($notification, $recipient, $template->channel_type, $template);

            $this->assertStringNotContainsString('{{', $rendered->subject.$rendered->body, "Plantilla {$template->code} sin interpolar");
            $this->assertStringContainsString('T-7', $rendered->body);
            $this->assertSame('🚨 PÁNICO: Botón de pánico', $rendered->subject);
        }
    }

    public function test_automation_templates_do_not_execute_php_and_interpolate_variables(): void
    {
        Mail::fake();
        $this->seed(NotificationMeterSeeder::class);

        $user = User::factory()->create(['name' => 'Original']);
        $team = $user->currentTeam;

        NotificationChannel::factory()->email()->create(['team_id' => $team->id]);

        $template = ActionTemplate::factory()->create([
            'team_id' => $team->id,
            'action_type' => ActionType::SendEmail,
            'subject_template' => 'Alerta: {{event_type}}',
            'body_template' => 'Evento {{ event_id }} '.self::PAYLOAD,
        ]);

        $execution = ActionExecution::factory()->create([
            'team_id' => $team->id,
            'action_type' => ActionType::SendEmail,
            'status' => ActionExecutionStatus::Queued,
            'action_template_id' => $template->id,
            'target_type' => 'email',
            'target_reference' => 'ops@example.test',
            'payload_json' => ['event_type' => 'panic', 'event_id' => 42],
        ]);

        $result = app(ExecuteAction::class)->execute($execution);

        $this->assertSame(ActionExecutionStatus::Completed, $result->status, (string) $result->error_message);
        $this->assertSame('Original', $user->fresh()->name);

        $notification = Notification::query()->findOrFail($result->response_json['notification_id']);
        $this->assertSame('Alerta: panic', $notification->subject);
    }
}
