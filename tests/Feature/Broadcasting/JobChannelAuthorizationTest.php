<?php

namespace Tests\Feature\Broadcasting;

use App\Domains\Tenancy\Models\Job;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

class JobChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('broadcasting.default', 'pusher');
        config()->set('broadcasting.connections.pusher', [
            'driver' => 'pusher',
            'key' => 'sam-key',
            'secret' => 'sam-secret',
            'app_id' => 'sam-local',
            'options' => [
                'host' => 'soketi',
                'port' => 6001,
                'scheme' => 'http',
                'encrypted' => false,
                'useTLS' => false,
            ],
        ]);

        require base_path('routes/channels.php');
        Broadcast::driver();
    }

    public function test_team_member_can_subscribe_to_job_channel(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $job = Job::factory()
            ->forTeam($team)
            ->ownedBy($user)
            ->create();

        $this->actingAs($user);

        $response = $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-jobs.{$job->id}",
        ]);

        $response->assertStatus(200);
    }

    public function test_user_from_different_team_cannot_subscribe_to_job_channel(): void
    {
        $owner = User::factory()->create();
        $ownerTeam = $owner->currentTeam;

        $job = Job::factory()
            ->forTeam($ownerTeam)
            ->ownedBy($owner)
            ->create();

        $foreigner = User::factory()->create();
        $this->actingAs($foreigner);

        $response = $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-jobs.{$job->id}",
        ]);

        $response->assertStatus(403);
    }

    public function test_unauthenticated_user_cannot_subscribe_to_job_channel(): void
    {
        $owner = User::factory()->create();
        $job = Job::factory()
            ->forTeam($owner->currentTeam)
            ->ownedBy($owner)
            ->create();

        $response = $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-jobs.{$job->id}",
        ]);

        $this->assertContains(
            $response->status(),
            [401, 403],
            'Unauthenticated subscription should be rejected'
        );
    }

    public function test_subscription_to_nonexistent_job_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-jobs.999999',
        ]);

        $response->assertStatus(403);
    }
}
