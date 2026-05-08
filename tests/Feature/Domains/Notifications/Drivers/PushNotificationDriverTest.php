<?php

namespace Tests\Feature\Domains\Notifications\Drivers;

use App\Domains\Notifications\Channels\FcmMessenger;
use App\Domains\Notifications\Channels\PushNotificationDriver;
use App\Domains\Notifications\Data\FcmSendReport;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\UserPushToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PushNotificationDriverTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_CREDS = '{"type":"service_account","project_id":"sam-test"}';

    private function rendered(int $userId): RenderedNotification
    {
        return new RenderedNotification(
            channelType: ChannelType::Push,
            address: (string) $userId,
            subject: 'New incident',
            body: 'Vehicle exceeded speed limit',
            variables: ['incident_id' => '99'],
        );
    }

    private function channel(Team $team, array $overrides = []): NotificationChannel
    {
        return NotificationChannel::factory()->create([
            'team_id' => $team->id,
            'channel_type' => ChannelType::Push,
            'provider' => 'firebase',
            'config_json' => array_merge([
                'firebase_credentials' => self::VALID_CREDS,
            ], $overrides),
        ]);
    }

    private function bindMessenger(): MockInterface
    {
        $mock = Mockery::mock(FcmMessenger::class);
        $this->app->instance(FcmMessenger::class, $mock);

        return $mock;
    }

    public function test_sends_to_all_user_tokens_and_returns_success(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        UserPushToken::factory()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'token' => 'tok-A',
        ]);
        UserPushToken::factory()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'token' => 'tok-B',
        ]);

        $messenger = $this->bindMessenger();
        $messenger->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(function (array $config, array $payload, array $tokens) {
                $this->assertSame(self::VALID_CREDS, $config['firebase_credentials']);
                $this->assertSame('New incident', $payload['title']);
                $this->assertSame('Vehicle exceeded speed limit', $payload['body']);
                $this->assertSame('99', $payload['data']['incident_id']);
                $this->assertCount(2, $tokens);

                return true;
            })
            ->andReturn(new FcmSendReport(successes: 2, failures: 0));

        $channel = $this->channel($team);

        $result = app(PushNotificationDriver::class)->send($this->rendered($user->id), $channel);

        $this->assertTrue($result->success);
        $this->assertSame(2, $result->response['successes']);
        $this->assertSame(0, $result->response['failures']);
    }

    public function test_prunes_unknown_tokens_after_send(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        UserPushToken::factory()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'token' => 'tok-good',
        ]);
        UserPushToken::factory()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'token' => 'tok-stale',
        ]);

        $messenger = $this->bindMessenger();
        $messenger->shouldReceive('sendMulticast')
            ->andReturn(new FcmSendReport(
                successes: 1,
                failures: 1,
                invalidTokens: ['tok-stale'],
            ));

        $channel = $this->channel($team);

        $result = app(PushNotificationDriver::class)->send($this->rendered($user->id), $channel);

        $this->assertTrue($result->success);
        $this->assertDatabaseHas('user_push_tokens', ['token' => 'tok-good']);
        $this->assertDatabaseMissing('user_push_tokens', ['token' => 'tok-stale']);
    }

    public function test_returns_failure_when_all_deliveries_fail(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        UserPushToken::factory()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'token' => 'tok-stale',
        ]);

        $messenger = $this->bindMessenger();
        $messenger->shouldReceive('sendMulticast')
            ->andReturn(new FcmSendReport(
                successes: 0,
                failures: 1,
                invalidTokens: ['tok-stale'],
            ));

        $channel = $this->channel($team);

        $result = app(PushNotificationDriver::class)->send($this->rendered($user->id), $channel);

        $this->assertFalse($result->success);
        $this->assertSame(0, $result->response['successes']);
        $this->assertSame(1, $result->response['failures']);
    }

    public function test_failure_when_user_has_no_tokens(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        $messenger = $this->bindMessenger();
        $messenger->shouldNotReceive('sendMulticast');

        $channel = $this->channel($team);

        $result = app(PushNotificationDriver::class)->send($this->rendered($user->id), $channel);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('no push tokens', $result->errorMessage);
    }

    public function test_failure_when_credentials_missing(): void
    {
        $messenger = $this->bindMessenger();
        $messenger->shouldNotReceive('sendMulticast');

        $team = Team::factory()->create();
        $channel = NotificationChannel::factory()->create([
            'team_id' => $team->id,
            'channel_type' => ChannelType::Push,
            'provider' => 'firebase',
            'config_json' => [],
        ]);

        $result = app(PushNotificationDriver::class)->send($this->rendered(123), $channel);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('firebase_credentials missing', $result->errorMessage);
    }

    public function test_failure_when_recipient_address_is_not_numeric(): void
    {
        $messenger = $this->bindMessenger();
        $messenger->shouldNotReceive('sendMulticast');

        $team = Team::factory()->create();
        $channel = $this->channel($team);

        $rendered = new RenderedNotification(
            channelType: ChannelType::Push,
            address: 'not-an-id',
            subject: 'x',
            body: 'y',
            variables: [],
        );

        $result = app(PushNotificationDriver::class)->send($rendered, $channel);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('numeric user id', $result->errorMessage);
    }
}
