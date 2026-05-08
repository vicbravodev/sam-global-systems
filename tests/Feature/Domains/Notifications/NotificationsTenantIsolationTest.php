<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationsTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifications_are_scoped_to_current_team(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        Notification::factory()->create(['team_id' => $userA->currentTeam->id]);
        Notification::factory()->create(['team_id' => $userB->currentTeam->id]);

        $this->actingAs($userA);

        $this->assertSame(1, Notification::query()->count());
        $this->assertSame(2, Notification::withoutGlobalScopes()->count());
    }

    public function test_recipients_and_deliveries_inherit_tenant_scope(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $teamAId = $userA->currentTeam->id;
        $teamBId = $userB->currentTeam->id;

        $notifA = Notification::factory()->create(['team_id' => $teamAId]);
        $notifB = Notification::factory()->create(['team_id' => $teamBId]);

        NotificationRecipient::factory()->create([
            'notification_id' => $notifA->id,
            'team_id' => $teamAId,
        ]);
        NotificationRecipient::factory()->create([
            'notification_id' => $notifB->id,
            'team_id' => $teamBId,
        ]);

        $this->actingAs($userA->fresh());

        $teamAVisible = NotificationRecipient::query()
            ->where('notification_recipients.team_id', $teamAId)
            ->count();
        $this->assertSame(1, $teamAVisible);

        $teamBVisible = NotificationRecipient::withoutGlobalScopes()
            ->where('team_id', $teamBId)
            ->count();
        $this->assertSame(1, $teamBVisible);
    }

    public function test_webhook_channel_secrets_do_not_leak_across_tenants(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        NotificationChannel::factory()->create([
            'team_id' => $userA->currentTeam->id,
            'channel_type' => ChannelType::Webhook,
            'provider' => 'webhook',
            'config_json' => [
                'endpoint_url' => 'https://example.com/team-a',
                'secret' => 'team-a-secret',
            ],
        ]);

        NotificationChannel::factory()->create([
            'team_id' => $userB->currentTeam->id,
            'channel_type' => ChannelType::Webhook,
            'provider' => 'webhook',
            'config_json' => [
                'endpoint_url' => 'https://example.com/team-b',
                'secret' => 'team-b-secret',
            ],
        ]);

        $this->actingAs($userA->fresh());

        $visible = NotificationChannel::query()
            ->where('team_id', $userA->currentTeam->id)
            ->get();
        $this->assertCount(1, $visible);
        $this->assertSame('team-a-secret', $visible->first()->config_json['secret']);
        $this->assertSame('https://example.com/team-a', $visible->first()->config_json['endpoint_url']);

        $other = NotificationChannel::withoutGlobalScopes()
            ->where('team_id', $userB->currentTeam->id)
            ->first();
        $this->assertSame('team-b-secret', $other->config_json['secret']);
    }
}
