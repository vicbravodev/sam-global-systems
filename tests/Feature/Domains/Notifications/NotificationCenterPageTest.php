<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationRead;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NotificationCenterPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $user = User::factory()->create();

        $response = $this->get(
            route('notifications.index', ['current_team' => $user->currentTeam->slug]),
        );

        $response->assertRedirect(route('login'));
    }

    public function test_member_without_notifications_view_gets_403(): void
    {
        [$user, $team] = $this->createUserWithRole('no_notifications', []);

        $response = $this->actingAs($user)->get(
            route('notifications.index', ['current_team' => $team->slug]),
        );

        $response->assertForbidden();
    }

    public function test_page_renders_notifications_with_row_shape(): void
    {
        [$user, $team] = $this->createUserWithRole('notif_viewer', ['notifications.view']);

        $notification = Notification::factory()->sent()->critical()->create([
            'team_id' => $team->id,
            'notification_type' => 'incident.panic_emergency.created',
            'subject' => 'Pánico en Camión 7',
            'body_preview' => 'Botón de pánico activado',
            'source_type' => NotificationSourceType::Incident,
            'source_reference_id' => '55',
        ]);

        $response = $this->actingAs($user)->get(
            route('notifications.index', ['current_team' => $team->slug]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('notifications/index')
                ->has('notifications', 1)
                ->has(
                    'notifications.0',
                    fn (Assert $row) => $row
                        ->where('id', $notification->id)
                        ->where('type', 'incident.panic_emergency.created')
                        ->where('priority', 'critical')
                        ->where('status', 'sent')
                        ->where('subject', 'Pánico en Camión 7')
                        ->where('bodyPreview', 'Botón de pánico activado')
                        ->where('sourceType', 'incident')
                        ->where('sourceUrl', route('incidents.show', [
                            'current_team' => $team->slug,
                            'incident' => 55,
                        ]))
                        ->where('isRead', false)
                        ->etc(),
                )
                ->has('pagination')
                ->has('filters')
                ->has('filterOptions.statuses')
                ->has('filterOptions.priorities'),
        );
    }

    public function test_notifications_of_other_teams_are_not_listed(): void
    {
        [$user, $team] = $this->createUserWithRole('notif_viewer_2', ['notifications.view']);

        Notification::factory()->create([
            'team_id' => $team->id,
            'subject' => 'Propia',
        ]);

        $foreignOwner = User::factory()->create();
        Notification::factory()->create([
            'team_id' => $foreignOwner->currentTeam->id,
            'subject' => 'Ajena',
        ]);

        $response = $this->actingAs($user)->get(
            route('notifications.index', ['current_team' => $team->slug]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('notifications/index')
                ->has('notifications', 1)
                ->where('notifications.0.subject', 'Propia'),
        );
    }

    public function test_status_and_priority_filters_narrow_the_list(): void
    {
        [$user, $team] = $this->createUserWithRole('notif_viewer_3', ['notifications.view']);

        Notification::factory()->sent()->create([
            'team_id' => $team->id,
            'priority' => NotificationPriority::Critical,
            'subject' => 'Crítica enviada',
        ]);
        Notification::factory()->create([
            'team_id' => $team->id,
            'status' => NotificationStatus::Failed,
            'priority' => NotificationPriority::Normal,
            'subject' => 'Normal fallida',
        ]);

        $response = $this->actingAs($user)->get(
            route('notifications.index', [
                'current_team' => $team->slug,
                'status' => 'sent',
                'priority' => 'critical',
            ]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('notifications/index')
                ->has('notifications', 1)
                ->where('notifications.0.subject', 'Crítica enviada')
                ->where('filters.status', 'sent')
                ->where('filters.priority', 'critical'),
        );
    }

    public function test_unread_filter_hides_notifications_read_by_the_user(): void
    {
        [$user, $team] = $this->createUserWithRole('notif_viewer_4', ['notifications.view']);

        $read = Notification::factory()->create([
            'team_id' => $team->id,
            'subject' => 'Ya leída',
        ]);
        Notification::factory()->create([
            'team_id' => $team->id,
            'subject' => 'Sin leer',
        ]);

        NotificationRead::factory()->create([
            'team_id' => $team->id,
            'notification_id' => $read->id,
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(
            route('notifications.index', [
                'current_team' => $team->slug,
                'unread' => 1,
            ]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('notifications/index')
                ->has('notifications', 1)
                ->where('notifications.0.subject', 'Sin leer')
                ->where('filters.unread', true),
        );
    }

    public function test_read_marker_is_per_user(): void
    {
        [$user, $team] = $this->createUserWithRole('notif_viewer_5', ['notifications.view']);

        $notification = Notification::factory()->create(['team_id' => $team->id]);

        // Another member reading it must NOT mark it read for this user.
        $otherMember = User::factory()->create();
        $team->members()->attach($otherMember, ['role' => TeamRole::Member->value]);
        NotificationRead::factory()->create([
            'team_id' => $team->id,
            'notification_id' => $notification->id,
            'user_id' => $otherMember->id,
        ]);

        $response = $this->actingAs($user)->get(
            route('notifications.index', ['current_team' => $team->slug]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('notifications/index')
                ->where('notifications.0.isRead', false),
        );
    }

    public function test_mark_read_endpoint_is_idempotent(): void
    {
        [$user, $team] = $this->createUserWithRole('notif_reader', ['notifications.view']);

        $notification = Notification::factory()->create(['team_id' => $team->id]);

        $url = route('notifications.read', [
            'current_team' => $team->slug,
            'notification' => $notification->id,
        ]);

        $this->actingAs($user)->post($url)->assertRedirect();
        $this->actingAs($user)->post($url)->assertRedirect();

        $this->assertSame(1, NotificationRead::query()
            ->withoutGlobalScopes()
            ->where('notification_id', $notification->id)
            ->where('user_id', $user->id)
            ->count());
    }

    public function test_mark_read_requires_notifications_view_permission(): void
    {
        [$user, $team] = $this->createUserWithRole('notif_no_read', []);

        $notification = Notification::factory()->create(['team_id' => $team->id]);

        $response = $this->actingAs($user)->post(route('notifications.read', [
            'current_team' => $team->slug,
            'notification' => $notification->id,
        ]));

        $response->assertForbidden();
        $this->assertSame(0, NotificationRead::query()->withoutGlobalScopes()->count());
    }

    public function test_mark_read_of_foreign_notification_is_404(): void
    {
        [$user, $team] = $this->createUserWithRole('notif_reader_2', ['notifications.view']);

        $foreignOwner = User::factory()->create();
        $foreign = Notification::factory()->create([
            'team_id' => $foreignOwner->currentTeam->id,
        ]);

        $response = $this->actingAs($user)->post(route('notifications.read', [
            'current_team' => $team->slug,
            'notification' => $foreign->id,
        ]));

        $response->assertNotFound();
        $this->assertSame(0, NotificationRead::query()->withoutGlobalScopes()->count());
    }

    /**
     * @param  array<string>  $permissionCodes
     * @return array{0: User, 1: Team}
     */
    private function createUserWithRole(string $roleCode, array $permissionCodes): array
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $role = Role::factory()->create([
            'code' => $roleCode,
            'scope' => RoleScope::Tenant,
        ]);

        $permissionIds = [];
        foreach ($permissionCodes as $code) {
            $permission = Permission::firstOrCreate(
                ['code' => $code],
                [
                    'name' => ucfirst(str_replace('.', ' ', $code)),
                    'module' => explode('.', $code, 2)[0],
                ],
            );
            $permissionIds[] = $permission->id;
        }
        $role->permissions()->sync($permissionIds);

        $team->members()->updateExistingPivot($user->id, [
            'role' => TeamRole::Member->value,
            'role_id' => $role->id,
        ]);

        return [$user, $team];
    }
}
