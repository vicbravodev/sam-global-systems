<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Actions\DispatchNotification;
use App\Domains\Notifications\Jobs\SendNotificationJob;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Models\User;
use Database\Seeders\NotificationMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendNotificationJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NotificationMeterSeeder::class);
    }

    public function test_job_runs_on_notifications_queue(): void
    {
        $job = new SendNotificationJob(1);

        $this->assertSame('notifications', $job->queue);
    }

    public function test_job_no_ops_when_notification_missing(): void
    {
        (new SendNotificationJob(999_999))->handle(app(DispatchNotification::class));

        $this->assertSame(0, Notification::withoutGlobalScopes()->count());
    }

    public function test_job_invokes_dispatch_notification(): void
    {
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

        NotificationChannel::factory()->email()->create([
            'team_id' => $user->currentTeam->id,
            'is_active' => true,
        ]);

        Mail::fake();

        (new SendNotificationJob($notification->id))->handle(app(DispatchNotification::class));

        $this->assertSame(
            1,
            NotificationDelivery::withoutGlobalScopes()
                ->where('notification_id', $notification->id)
                ->count(),
        );
    }
}
