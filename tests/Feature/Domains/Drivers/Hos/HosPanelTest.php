<?php

namespace Tests\Feature\Domains\Drivers\Hos;

use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Assets\Models\Asset;
use App\Domains\Drivers\Actions\ListHosFleet;
use App\Domains\Drivers\Enums\HosDutyStatus;
use App\Domains\Drivers\Enums\HosEpisodeResolution;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\HosDriverState;
use App\Domains\Drivers\Models\HosEpisode;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class HosPanelTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
    }

    private function enable(Team $team): void
    {
        TenantFeature::factory()->create(['team_id' => $team->id, 'feature_key' => HosMonitoringConfig::FEATURE_KEY, 'enabled' => true]);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function monitored(Team $team, string $name, array $state = []): Driver
    {
        $driver = Driver::factory()->create(['team_id' => $team->id, 'full_name' => $name]);
        HosDriverState::factory()->create(['driver_id' => $driver->id] + $state);

        return $driver;
    }

    public function test_the_driver_page_carries_the_hos_panel_with_episodes_nudges_and_incident(): void
    {
        $owner = User::factory()->create();
        $team = $owner->currentTeam;
        $this->enable($team);
        $asset = Asset::factory()->create(['team_id' => $team->id, 'name' => 'T-0321']);
        $driver = $this->monitored($team, 'Chofer Uno', ['asset_id' => $asset->id, 'duty_status' => HosDutyStatus::Driving, 'break_remaining_s' => 600]);
        $incident = Incident::factory()->create(['team_id' => $team->id]);
        $open = HosEpisode::factory()->create([
            'driver_id' => $driver->id, 'asset_id' => $asset->id, 'situation' => HosSituation::BreakDue,
            'ladder_step' => 2, 'escalated_at' => now(), 'incident_id' => $incident->id,
        ]);
        HosEpisode::factory()->create([
            'driver_id' => $driver->id, 'situation' => HosSituation::DriveLimit,
            'opened_at' => now()->subDay(), 'resolved_at' => now()->subDay()->addHour(), 'resolution' => HosEpisodeResolution::Corrected,
        ]);
        $channel = NotificationChannel::factory()->samsaraDriverApp()->create();
        $notification = Notification::factory()->create([
            'team_id' => $team->id,
            'source_type' => NotificationSourceType::HosEpisode,
            'source_reference_id' => (string) $open->id,
            'payload_json' => ['hos' => ['episode_id' => $open->id, 'step' => 1, 'notice' => 'break_insist']],
        ]);
        NotificationDelivery::factory()->delivered()->create(['notification_id' => $notification->id, 'channel_id' => $channel->id]);

        $this->actingAs($owner)
            ->get(route('drivers.show', ['current_team' => $team->slug, 'driver' => $driver->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('drivers/show')
                ->where('hos.state.dutyStatus', 'driving')
                ->where('hos.state.clocks.break', 600)
                ->where('hos.state.asset.name', 'T-0321')
                ->where('hos.state.stale', false)
                ->where('hos.openEpisodes.0.situation', 'break_due')
                ->where('hos.openEpisodes.0.ladderStep', 2)
                ->where('hos.openEpisodes.0.incident.id', $incident->id)
                ->where('hos.openEpisodes.0.nudges.0.notice', 'break_insist')
                ->where('hos.openEpisodes.0.nudges.0.step', 1)
                ->where('hos.openEpisodes.0.nudges.0.deliveries.0', ['channel' => 'samsara_driver_app', 'status' => 'delivered'])
                ->where('hos.history.0.resolution', 'corrected')
            );
    }

    public function test_without_the_feature_the_driver_page_has_no_hos_panel(): void
    {
        $owner = User::factory()->create();
        $driver = $this->monitored($owner->currentTeam, 'Chofer Uno');

        $this->actingAs($owner)
            ->get(route('drivers.show', ['current_team' => $owner->currentTeam->slug, 'driver' => $driver->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('hos', null));
    }

    public function test_the_fleet_orders_violation_then_at_limit_then_lowest_remaining(): void
    {
        $owner = User::factory()->create();
        $team = $owner->currentTeam;
        $this->enable($team);

        $this->monitored($team, 'E Descansando', ['duty_status' => HosDutyStatus::OffDuty, 'drive_remaining_s' => 0]);
        $this->monitored($team, 'D Holgado', ['duty_status' => HosDutyStatus::Driving, 'break_remaining_s' => 7200]);
        $warning = $this->monitored($team, 'C Aviso', ['duty_status' => HosDutyStatus::Driving, 'break_remaining_s' => 900]);
        HosEpisode::factory()->create(['driver_id' => $warning->id, 'situation' => HosSituation::BreakDue]);
        $this->monitored($team, 'B Límite', ['duty_status' => HosDutyStatus::Driving, 'break_remaining_s' => 0]);
        $this->monitored($team, 'A Infracción', ['duty_status' => HosDutyStatus::OffDuty, 'violation_s' => 120]);
        $this->monitored($team, 'F Viejo', ['observed_at' => now()->subHour()]);

        $this->actingAs($owner)
            ->getJson("/api/{$team->slug}/drivers/hos")
            ->assertOk()
            ->assertJsonPath('data.rows.*.driver.fullName', ['A Infracción', 'B Límite', 'C Aviso', 'D Holgado', 'E Descansando'])
            ->assertJsonPath('data.rows.*.urgency', ['violation', 'at_limit', 'warning', 'ok', 'ok'])
            ->assertJsonPath('data.rows.2.openEpisodes.0.situation', 'break_due')
            ->assertJsonPath('data.summary', ['total' => 5, 'violation' => 1, 'at_limit' => 1, 'warning' => 1, 'ok' => 2]);
    }

    public function test_the_api_mirrors_the_driver_panel(): void
    {
        $owner = User::factory()->create();
        $team = $owner->currentTeam;
        $this->enable($team);
        $driver = $this->monitored($team, 'Chofer Uno', ['duty_status' => HosDutyStatus::SleeperBed]);

        $this->actingAs($owner)
            ->getJson("/api/{$team->slug}/drivers/{$driver->id}/hos")
            ->assertOk()
            ->assertJsonPath('data.state.dutyStatus', 'sleeperBed')
            ->assertJsonPath('data.openEpisodes', []);
    }

    public function test_the_api_answers_403_without_the_feature(): void
    {
        $owner = User::factory()->create();
        $team = $owner->currentTeam;
        $driver = $this->monitored($team, 'Chofer Uno');

        $this->actingAs($owner)->getJson("/api/{$team->slug}/drivers/hos")->assertForbidden();
        $this->actingAs($owner)->getJson("/api/{$team->slug}/drivers/{$driver->id}/hos")->assertForbidden();
    }

    public function test_a_role_without_drivers_view_gets_403(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $role = Role::factory()->create(['code' => 'only_config', 'scope' => RoleScope::Tenant]);
        $role->permissions()->sync([Permission::firstOrCreate(['code' => 'config.view'], ['name' => 'config.view', 'module' => 'config'])->id]);
        $team->members()->updateExistingPivot($user->id, ['role' => TeamRole::Member->value, 'role_id' => $role->id]);
        $this->enable($team);

        $this->actingAs($user)->getJson("/api/{$team->slug}/drivers/hos")->assertForbidden();
    }

    public function test_the_fleet_and_panel_never_show_another_tenant(): void
    {
        $owner = User::factory()->create();
        $team = $owner->currentTeam;
        $this->enable($team);
        $this->monitored($team, 'Mío');

        $other = User::factory()->create()->currentTeam;
        $this->enable($other);
        $foreign = $this->monitored($other, 'Ajeno', ['violation_s' => 300]);
        HosEpisode::factory()->create(['driver_id' => $foreign->id, 'situation' => HosSituation::Violation]);

        $response = $this->assertNoTenantLeak($team, fn () => $this->actingAs($owner)->getJson("/api/{$team->slug}/drivers/hos"));

        $response->assertOk()->assertJsonPath('data.rows.*.driver.fullName', ['Mío']);
        $this->actingAs($owner)->getJson("/api/{$team->slug}/drivers/{$foreign->id}/hos")->assertNotFound();
    }

    public function test_the_driver_panel_ignores_notifications_and_incidents_of_another_tenant(): void
    {
        $owner = User::factory()->create();
        $team = $owner->currentTeam;
        $this->enable($team);
        $driver = $this->monitored($team, 'Chofer Uno');
        $episode = HosEpisode::factory()->create(['driver_id' => $driver->id, 'situation' => HosSituation::BreakDue]);

        // Otro tenant con un aviso HOS cuyo source_reference_id coincide con mi episodio.
        $other = User::factory()->create()->currentTeam;
        Notification::factory()->create([
            'team_id' => $other->id,
            'source_type' => NotificationSourceType::HosEpisode,
            'source_reference_id' => (string) $episode->id,
            'payload_json' => ['hos' => ['episode_id' => $episode->id, 'step' => 1, 'notice' => 'break_insist']],
        ]);
        $mine = Notification::factory()->create([
            'team_id' => $team->id,
            'source_type' => NotificationSourceType::HosEpisode,
            'source_reference_id' => (string) $episode->id,
            'body_preview' => 'Tienes que tomar tu descanso',
            'payload_json' => ['hos' => ['episode_id' => $episode->id, 'step' => 0, 'notice' => 'break_warning']],
        ]);

        $response = $this->assertNoTenantLeak($team, fn () => $this->actingAs($owner)
            ->get(route('drivers.show', ['current_team' => $team->slug, 'driver' => $driver->id])));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('hos.openEpisodes.0.nudges', 1)
            ->where('hos.openEpisodes.0.nudges.0.id', $mine->id)
            // Sólo lo que la UI necesita: ni texto del mensaje ni destinatarios.
            ->has('hos.openEpisodes.0.nudges.0', fn (Assert $nudge) => $nudge
                ->hasAll(['id', 'step', 'notice', 'createdAt', 'deliveries'])
            )
        );
    }

    public function test_the_fleet_page_renders_for_whoever_sees_drivers_with_the_feature(): void
    {
        $owner = User::factory()->create();
        $team = $owner->currentTeam;
        $this->enable($team);
        $this->monitored($team, 'Chofer Uno', ['duty_status' => HosDutyStatus::Driving, 'break_remaining_s' => 900]);

        $this->actingAs($owner)
            ->get(route('drivers.hos.index', ['current_team' => $team->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('drivers/hos')
                ->has('fleet.rows', 1)
                ->where('fleet.rows.0.driver.fullName', 'Chofer Uno')
                ->where('fleet.summary.total', 1)
            );
    }

    public function test_the_fleet_page_answers_403_without_the_feature(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->get(route('drivers.hos.index', ['current_team' => $owner->currentTeam->slug]))
            ->assertForbidden();
    }

    public function test_the_fleet_runs_a_fixed_number_of_queries(): void
    {
        $team = User::factory()->create()->currentTeam;
        $this->enable($team);

        $seed = function (int $count) use ($team): void {
            foreach (range(1, $count) as $i) {
                $asset = Asset::factory()->create(['team_id' => $team->id]);
                $driver = $this->monitored($team, "Chofer {$i}", ['asset_id' => $asset->id, 'duty_status' => HosDutyStatus::Driving]);
                HosEpisode::factory()->create(['driver_id' => $driver->id, 'situation' => HosSituation::BreakDue]);
            }
        };

        $queries = function () use ($team): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(ListHosFleet::class)->execute($team->id, now());
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $seed(1);
        $one = $queries();
        $seed(4);

        $this->assertSame($one, $queries());
    }
}
