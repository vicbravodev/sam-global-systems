<?php

namespace Tests\Feature\Domains\Notifications;

use App\Contracts\Notifications\ChannelDriverRegistry;
use App\Contracts\Notifications\NotificationDriver;
use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

/**
 * Roadmap F5c: tenant notification channel management (CRUD + probar canal)
 * with secrets masked before they reach the browser.
 */
class NotificationChannelManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);

        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;
    }

    public function test_config_page_lists_channels_with_masked_secrets(): void
    {
        NotificationChannel::factory()->sms()->create([
            'team_id' => $this->team->id,
            'name' => 'Twilio SMS',
            'config_json' => [
                'twilio_account_sid' => 'AC1234567890',
                'twilio_auth_token' => 'super-secret-token-9876',
                'from' => '+14155238886',
            ],
        ]);

        $response = $this->actingAs($this->user)->get(
            route('tenant-config.show', ['current_team' => $this->team->slug]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('settings/tenant-config')
                ->has('channels', 1)
                ->where('channels.0.name', 'Twilio SMS')
                ->where('channels.0.configSummary.twilio_auth_token', '••••9876')
                ->has('channelTypes')
                ->where('canManageChannels', true),
        );

        // The raw secret must never appear in the page payload.
        $this->assertStringNotContainsString('super-secret-token-9876', $response->getContent());
    }

    public function test_channel_can_be_created_via_web_route(): void
    {
        $response = $this->actingAs($this->user)->postJson(
            route('tenant-config.channels.store', ['current_team' => $this->team->slug]),
            [
                'code' => 'slack_ops',
                'name' => 'Slack #ops',
                'provider' => 'slack',
                'channel_type' => 'slack',
                'config_json' => ['slack_webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXXX'],
            ],
        );

        $response->assertCreated();

        $channel = NotificationChannel::query()
            ->where('team_id', $this->team->id)
            ->where('code', 'slack_ops')
            ->first();

        $this->assertNotNull($channel);
        $this->assertSame(ChannelType::Slack, $channel->channel_type);
        $this->assertSame(
            'https://hooks.slack.com/services/T000/B000/XXXX',
            $channel->config_json['slack_webhook_url'],
        );

        // Encrypted at rest: the raw column must not contain the secret.
        $raw = (string) DB::table('notification_channels')
            ->where('id', $channel->id)
            ->value('config_json');
        $this->assertStringNotContainsString('hooks.slack.com', $raw);
    }

    public function test_channel_test_endpoint_sends_through_the_driver(): void
    {
        $channel = NotificationChannel::factory()->sms()->create([
            'team_id' => $this->team->id,
        ]);

        $driver = Mockery::mock(NotificationDriver::class);
        $driver->shouldReceive('send')
            ->once()
            ->andReturn(DeliveryResult::success('SM-test'));

        $registry = Mockery::mock(ChannelDriverRegistry::class);
        $registry->shouldReceive('driverFor')->andReturn($driver);
        $this->app->instance(ChannelDriverRegistry::class, $registry);

        $response = $this->actingAs($this->user)->postJson(
            route('tenant-config.channels.test', [
                'current_team' => $this->team->slug,
                'channel' => $channel->id,
            ]),
            ['address' => '+5215512345678'],
        );

        $response->assertOk();
        $response->assertJsonPath('data.success', true);
    }

    public function test_channel_test_reports_driver_failure(): void
    {
        $channel = NotificationChannel::factory()->sms()->create([
            'team_id' => $this->team->id,
        ]);

        $driver = Mockery::mock(NotificationDriver::class);
        $driver->shouldReceive('send')->andReturn(DeliveryResult::failure('credenciales inválidas'));

        $registry = Mockery::mock(ChannelDriverRegistry::class);
        $registry->shouldReceive('driverFor')->andReturn($driver);
        $this->app->instance(ChannelDriverRegistry::class, $registry);

        $response = $this->actingAs($this->user)->postJson(
            route('tenant-config.channels.test', [
                'current_team' => $this->team->slug,
                'channel' => $channel->id,
            ]),
            ['address' => '+5215512345678'],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('data.error', 'credenciales inválidas');
    }

    public function test_global_channel_cannot_be_deleted_nor_edited_by_a_tenant(): void
    {
        $global = NotificationChannel::factory()->email()->create(['team_id' => null]);

        // V2-B1: platform channels are entirely off-limits for tenants —
        // delete, edit and test all hit the policy (only toggle is allowed).
        $this->actingAs($this->user)->deleteJson(
            route('tenant-config.channels.destroy', [
                'current_team' => $this->team->slug,
                'channel' => $global->id,
            ]),
        )->assertForbidden();

        $this->actingAs($this->user)->putJson(
            route('tenant-config.channels.update', [
                'current_team' => $this->team->slug,
                'channel' => $global->id,
            ]),
            ['is_active' => false],
        )->assertForbidden();
    }

    public function test_tenant_can_toggle_a_global_channel_for_itself(): void
    {
        $global = NotificationChannel::factory()->voice()->create(['team_id' => null]);

        $toggle = fn (bool $enabled) => $this->actingAs($this->user)->postJson(
            route('tenant-config.channels.toggle', [
                'current_team' => $this->team->slug,
                'channel' => $global->id,
            ]),
            ['enabled' => $enabled],
        );

        $toggle(false)->assertOk()->assertJsonPath('data.enabled', false);

        $this->assertDatabaseHas('tenant_channel_toggles', [
            'team_id' => $this->team->id,
            'notification_channel_id' => $global->id,
            'enabled' => false,
        ]);

        // Idempotent upsert: re-enabling reuses the same row.
        $toggle(true)->assertOk()->assertJsonPath('data.enabled', true);
        $this->assertSame(1, DB::table('tenant_channel_toggles')->count());

        // The platform channel itself was never touched.
        $this->assertTrue($global->fresh()->is_active);
    }

    public function test_toggle_rejects_non_global_channels(): void
    {
        $own = NotificationChannel::factory()->sms()->create(['team_id' => $this->team->id]);

        $this->actingAs($this->user)->postJson(
            route('tenant-config.channels.toggle', [
                'current_team' => $this->team->slug,
                'channel' => $own->id,
            ]),
            ['enabled' => false],
        )->assertForbidden();
    }

    public function test_disabled_global_channel_is_excluded_from_team_usable_channels(): void
    {
        $global = NotificationChannel::factory()->voice()->create(['team_id' => null]);
        $own = NotificationChannel::factory()->sms()->create(['team_id' => $this->team->id]);
        $otherTeam = User::factory()->create()->currentTeam;

        DB::table('tenant_channel_toggles')->insert([
            'team_id' => $this->team->id,
            'notification_channel_id' => $global->id,
            'enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mine = NotificationChannel::query()->usableByTeam($this->team->id)->pluck('id')->all();
        $theirs = NotificationChannel::query()->usableByTeam($otherTeam->id)->pluck('id')->all();

        // My team lost the global but keeps its own channel…
        $this->assertEqualsCanonicalizing([$own->id], $mine);
        // …while the other tenant still sees SAM's channel (no leak).
        $this->assertContains($global->id, $theirs);
    }

    public function test_other_tenant_channel_cannot_be_managed(): void
    {
        $foreign = NotificationChannel::factory()->sms()->create([
            'team_id' => User::factory()->create()->currentTeam->id,
        ]);

        $this->actingAs($this->user)->deleteJson(
            route('tenant-config.channels.destroy', [
                'current_team' => $this->team->slug,
                'channel' => $foreign->id,
            ]),
        )->assertForbidden();
    }
}
