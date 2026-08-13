<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Models\NotificationChannel;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * SAM opera la mensajería centralmente (credenciales Twilio en env): los
 * tenants ya no configuran canales — sólo ven los canales de plataforma y
 * pueden apagarlos/encenderlos para su equipo (V2-B1).
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

    public function test_tenant_channel_config_routes_no_longer_exist(): void
    {
        foreach ([
            'tenant-config.channels.store',
            'tenant-config.channels.update',
            'tenant-config.channels.destroy',
            'tenant-config.channels.test',
            'api.notifications.channels.index',
            'api.notifications.channels.update',
        ] as $name) {
            $this->assertFalse(Route::has($name), "Route [{$name}] should be gone: tenants no configuran mensajería.");
        }
    }

    public function test_config_page_lists_channels_without_credential_data(): void
    {
        NotificationChannel::factory()->sms()->create([
            'team_id' => null,
            'name' => 'SMS SAM (Twilio)',
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
                ->where('channels.0.name', 'SMS SAM (Twilio)')
                ->missing('channels.0.configSummary')
                ->where('canManageChannels', true),
        );

        // Nothing derived from credentials reaches the browser anymore.
        $this->assertStringNotContainsString('super-secret-token-9876', $response->getContent());
        $this->assertStringNotContainsString('AC1234567890', $response->getContent());
    }

    public function test_config_json_is_hidden_when_a_channel_is_serialized(): void
    {
        $channel = NotificationChannel::factory()->voice()->create(['team_id' => null]);
        $sid = $channel->config_json['twilio_account_sid'];

        $this->assertArrayNotHasKey('config_json', $channel->toArray());
        $this->assertStringNotContainsString($sid, (string) json_encode($channel));
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
}
