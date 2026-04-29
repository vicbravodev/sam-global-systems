<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Models\Notification;
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
}
