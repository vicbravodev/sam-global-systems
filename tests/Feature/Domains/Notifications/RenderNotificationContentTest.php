<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Actions\RenderNotificationContent;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\Notifications\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RenderNotificationContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_renders_with_variables(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        $template = NotificationTemplate::factory()->create([
            'team_id' => $team->id,
            'channel_type' => ChannelType::Email,
            'event_type' => 'incident.created',
            'subject_template' => 'Incident: {{ $incident_type }}',
            'body_template' => 'Asset {{ $asset_name }} reported {{ $incident_type }}',
        ]);

        $notification = Notification::factory()->create([
            'team_id' => $team->id,
            'notification_type' => 'incident.created',
            'template_id' => $template->id,
            'payload_json' => ['incident_type' => 'speeding', 'asset_name' => 'Truck-7'],
        ]);

        $recipient = NotificationRecipient::factory()->create([
            'notification_id' => $notification->id,
            'team_id' => $team->id,
            'address' => 'driver@example.com',
            'name' => 'John Driver',
        ]);

        $rendered = app(RenderNotificationContent::class)->execute(
            $notification,
            $recipient,
            ChannelType::Email,
            $template,
        );

        $this->assertSame('Incident: speeding', $rendered->subject);
        $this->assertStringContainsString('Truck-7', $rendered->body);
        $this->assertStringContainsString('speeding', $rendered->body);
        $this->assertSame('driver@example.com', $rendered->address);
        $this->assertSame(ChannelType::Email, $rendered->channelType);
    }

    public function test_falls_back_to_notification_subject_when_no_template(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        $notification = Notification::factory()->create([
            'team_id' => $team->id,
            'notification_type' => 'unmapped.event',
            'subject' => 'Plain subject',
            'body_preview' => 'Plain body preview',
            'template_id' => null,
            'payload_json' => [],
        ]);

        $recipient = NotificationRecipient::factory()->create([
            'notification_id' => $notification->id,
            'team_id' => $team->id,
        ]);

        $rendered = app(RenderNotificationContent::class)->execute(
            $notification,
            $recipient,
            ChannelType::Email,
        );

        $this->assertSame('Plain subject', $rendered->subject);
        $this->assertSame('Plain body preview', $rendered->body);
    }
}
