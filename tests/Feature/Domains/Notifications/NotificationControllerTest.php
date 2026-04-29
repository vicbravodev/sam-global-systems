<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Jobs\SendNotificationJob;
use App\Domains\Notifications\Models\Notification;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
    }

    public function test_index_lists_team_notifications(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        Notification::factory()->create(['team_id' => $team->id]);
        Notification::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user);

        $response = $this->getJson("/api/{$team->slug}/notifications");

        $response->assertOk();
        $this->assertSame(2, count($response->json('data')));
    }

    public function test_send_endpoint_dispatches_send_job(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;

        $this->actingAs($user);

        $response = $this->postJson("/api/{$team->slug}/notifications/send", [
            'notification_type' => 'manual.api',
            'priority' => 'normal',
            'subject' => 'Hello',
            'body_preview' => 'Body',
            'recipients' => [
                ['recipient_type' => 'external_contact', 'address' => 'ops@example.com'],
            ],
        ]);

        $response->assertStatus(202);
        Bus::assertDispatched(SendNotificationJob::class);
    }

    public function test_cross_tenant_show_is_blocked(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $notification = Notification::factory()->create(['team_id' => $userA->currentTeam->id]);

        $this->actingAs($userB);

        $response = $this->getJson("/api/{$userA->currentTeam->slug}/notifications/{$notification->id}");

        $this->assertContains($response->status(), [403, 404]);
    }
}
