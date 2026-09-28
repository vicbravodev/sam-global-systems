<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Notifications\Actions\DispatchNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Enums\RecipientType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\NotificationRead;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Enums\TeamRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class NotificationCenterPageTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

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
        $unread = Notification::factory()->create([
            'team_id' => $team->id,
            'subject' => 'Sin leer',
        ]);
        $this->addressTo($read, $user);
        $this->addressTo($unread, $user);

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

    public function test_cancelled_without_recipients_explains_why_it_was_not_sent(): void
    {
        [$user, $team] = $this->createUserWithRole('notif_cancel_1', ['notifications.view']);

        Notification::factory()->create([
            'team_id' => $team->id,
            'status' => NotificationStatus::Cancelled,
        ]);

        $response = $this->actingAs($user)->get(
            route('notifications.index', ['current_team' => $team->slug]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('notifications/index')
                ->where('notifications.0.status', 'cancelled')
                ->where(
                    'notifications.0.statusReason',
                    'No se envió: ninguna persona del equipo tenía datos de contacto al momento de generarse.',
                ),
        );
    }

    public function test_cancelled_with_recipients_but_no_deliveries_blames_channels(): void
    {
        [$user, $team] = $this->createUserWithRole('notif_cancel_2', ['notifications.view']);

        $notification = Notification::factory()->create([
            'team_id' => $team->id,
            'status' => NotificationStatus::Cancelled,
        ]);
        NotificationRecipient::factory()->create([
            'notification_id' => $notification->id,
            'team_id' => $team->id,
        ]);

        $response = $this->actingAs($user)->get(
            route('notifications.index', ['current_team' => $team->slug]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('notifications/index')
                ->where(
                    'notifications.0.statusReason',
                    'No se envió: no había ningún canal de notificación activo o permitido para este aviso.',
                ),
        );
    }

    public function test_cancelled_with_only_skipped_deliveries_blames_missing_contact_data(): void
    {
        [$user, $team] = $this->createUserWithRole('notif_cancel_skip', ['notifications.view']);

        // A critical SMS-only notification whose recipient has no phone: the
        // dispatcher records a Skipped delivery and cancels the notification.
        NotificationChannel::factory()->sms()->create([
            'is_active' => true,
            'channel_type' => ChannelType::Sms,
        ]);

        $notification = Notification::factory()->critical()->create([
            'team_id' => $team->id,
            'notification_type' => 'incident.critical',
            'status' => NotificationStatus::Queued,
            'payload_json' => [
                'recipients' => [
                    ['recipient_type' => RecipientType::ExternalContact->value, 'address' => 'ops@example.com'],
                ],
            ],
        ]);

        app(DispatchNotification::class)->execute($notification);

        $this->assertSame(NotificationStatus::Cancelled, $notification->refresh()->status);
        $this->assertTrue(
            $notification->deliveries()
                ->where('status', DeliveryStatus::Skipped)
                ->exists(),
        );

        // Inertia request (X-Inertia) returns the page props as JSON without
        // rendering the root Blade view, so no Vite manifest is required. The
        // version header must match the middleware's asset version (null in
        // tests, which Inertia compares as an empty string) or it 409s.
        $version = (string) app(HandleInertiaRequests::class)->version(request());

        $response = $this->actingAs($user)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => $version,
            ])
            ->get(route('notifications.index', ['current_team' => $team->slug]));

        $response->assertOk();
        $response->assertHeader('X-Inertia', 'true');
        $response->assertJsonPath('component', 'notifications/index');
        $response->assertJsonPath('props.notifications.0.status', 'cancelled');
        $response->assertJsonPath(
            'props.notifications.0.statusReason',
            'No se envió: faltan datos de contacto (teléfono o email) para los canales seleccionados.',
        );
    }

    public function test_non_cancelled_notifications_have_no_status_reason(): void
    {
        [$user, $team] = $this->createUserWithRole('notif_cancel_3', ['notifications.view']);

        $notification = Notification::factory()->sent()->create([
            'team_id' => $team->id,
        ]);
        $recipient = NotificationRecipient::factory()->create([
            'notification_id' => $notification->id,
            'team_id' => $team->id,
        ]);
        NotificationDelivery::factory()->delivered()->create([
            'notification_id' => $notification->id,
            'recipient_id' => $recipient->id,
            'team_id' => $team->id,
        ]);

        $response = $this->actingAs($user)->get(
            route('notifications.index', ['current_team' => $team->slug]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('notifications/index')
                ->where('notifications.0.status', 'sent')
                ->where('notifications.0.statusReason', null),
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

    public function test_rows_expose_one_chip_per_channel_with_the_worst_delivery_status(): void
    {
        [$user, $team] = $this->createUserWithRole('notif_channels', ['notifications.view']);

        $notification = Notification::factory()->sent()->create(['team_id' => $team->id]);
        $sms = NotificationChannel::factory()->sms()->create();
        $email = NotificationChannel::factory()->email()->create();

        NotificationDelivery::factory()->create([
            'notification_id' => $notification->id,
            'channel_id' => $sms->id,
            'status' => DeliveryStatus::Delivered,
        ]);
        NotificationDelivery::factory()->create([
            'notification_id' => $notification->id,
            'channel_id' => $sms->id,
            'status' => DeliveryStatus::Failed,
        ]);
        NotificationDelivery::factory()->create([
            'notification_id' => $notification->id,
            'channel_id' => $email->id,
            'status' => DeliveryStatus::Delivered,
        ]);

        $response = $this->actingAs($user)->get(
            route('notifications.index', ['current_team' => $team->slug]),
        );

        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('notifications/index')
                ->where('notifications.0.recipientsCount', 3)
                ->has('notifications.0.channels', 2)
                ->where('notifications.0.channels.0.type', 'sms')
                ->where('notifications.0.channels.0.status', 'failed')
                ->where('notifications.0.channels.0.count', 2)
                ->where('notifications.0.channels.1.type', 'email')
                ->where('notifications.0.channels.1.status', 'delivered'),
        );
    }

    public function test_summary_counts_unread_for_this_user_and_the_last_24h_of_the_tenant(): void
    {
        [$user, $team] = $this->createUserWithRole('notif_summary', ['notifications.view']);
        $other = Team::factory()->create();

        $read = Notification::factory()->sent()->create(['team_id' => $team->id]);
        NotificationRead::factory()->create([
            'team_id' => $team->id,
            'notification_id' => $read->id,
            'user_id' => $user->id,
        ]);
        $this->addressTo($read, $user);
        $this->addressTo(Notification::factory()->sent()->critical()->create(['team_id' => $team->id]), $user);
        $this->addressTo(Notification::factory()->create(['team_id' => $team->id, 'status' => NotificationStatus::Failed]), $user);
        $this->addressTo(Notification::factory()->create(['team_id' => $team->id, 'status' => NotificationStatus::Cancelled]), $user);
        // Older than a day: unread but outside the 24 h counters.
        $old = Notification::factory()->sent()->critical()->create(['team_id' => $team->id]);
        $old->forceFill(['created_at' => now()->subDays(2)])->save();
        $this->addressTo($old, $user);
        Notification::factory()->sent()->critical()->count(3)->create(['team_id' => $other->id]);

        $response = $this->actingAs($user)->get(
            route('notifications.index', ['current_team' => $team->slug, 'unread' => 1]),
        );

        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('notifications/index')
                ->where('summary.unread', 4)
                ->where('summary.sent24h', 2)
                ->where('summary.undelivered24h', 2)
                ->where('summary.critical24h', 1),
        );
    }

    /**
     * UI audit P1-6: "sin leer" counted every tenant notification the user
     * had not opened, including the ones sent to other operators.
     */
    public function test_unread_counts_only_notifications_addressed_to_the_user(): void
    {
        [$user, $team] = $this->createUserWithRole('notif_unread_mine', ['notifications.view']);
        $colleague = User::factory()->create();

        $mine = Notification::factory()->sent()->create(['team_id' => $team->id, 'subject' => 'Para mí']);
        $this->addressTo($mine, $user);
        $theirs = Notification::factory()->sent()->create(['team_id' => $team->id, 'subject' => 'Para otro']);
        $this->addressTo($theirs, $colleague);

        $response = $this->actingAs($user)->get(
            route('notifications.index', ['current_team' => $team->slug]),
        );

        $response->assertInertia(fn (Assert $page) => $page
            ->where('summary.unread', 1)
            ->has('notifications', 2));

        $rows = collect($response->viewData('page')['props']['notifications'])->keyBy('subject');
        $this->assertTrue($rows['Para mí']['addressedToMe']);
        $this->assertFalse($rows['Para otro']['addressedToMe']);

        $this->actingAs($user)
            ->get(route('notifications.index', ['current_team' => $team->slug, 'unread' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('notifications', 1)
                ->where('notifications.0.subject', 'Para mí'));
    }

    /**
     * UI audit P1-6: "No entregadas 24 h" showed 0 while deliveries had
     * failed, because it only looked at the notification status.
     */
    public function test_undelivered_counter_includes_notifications_with_failed_deliveries(): void
    {
        [$user, $team] = $this->createUserWithRole('notif_undelivered', ['notifications.view']);
        $other = Team::factory()->create();
        $sms = NotificationChannel::factory()->sms()->create();

        $withFailure = Notification::factory()->sent()->create(['team_id' => $team->id]);
        $recipient = NotificationRecipient::factory()->create(['notification_id' => $withFailure->id, 'team_id' => $team->id]);
        NotificationDelivery::factory()->failed()->create([
            'notification_id' => $withFailure->id,
            'recipient_id' => $recipient->id,
            'channel_id' => $sms->id,
            'team_id' => $team->id,
        ]);
        Notification::factory()->sent()->create(['team_id' => $team->id]);

        // Another tenant's failures never count here.
        $foreign = Notification::factory()->sent()->create(['team_id' => $other->id]);
        $foreignRecipient = NotificationRecipient::factory()->create(['notification_id' => $foreign->id, 'team_id' => $other->id]);
        NotificationDelivery::factory()->failed()->create([
            'notification_id' => $foreign->id,
            'recipient_id' => $foreignRecipient->id,
            'channel_id' => $sms->id,
            'team_id' => $other->id,
        ]);

        $response = $this->assertNoTenantLeak($team, fn () => $this->actingAs($user)->get(
            route('notifications.index', ['current_team' => $team->slug]),
        ));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('summary.undelivered24h', 1)
            ->has('notifications', 2));

        $this->actingAs($user)
            ->get(route('notifications.index', ['current_team' => $team->slug, 'failures' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('notifications', 1)
                ->where('notifications.0.id', $withFailure->id));
    }

    private function addressTo(Notification $notification, User $user): NotificationRecipient
    {
        return NotificationRecipient::factory()->create([
            'notification_id' => $notification->id,
            'team_id' => $notification->team_id,
            'recipient_type' => RecipientType::User,
            'recipient_reference_id' => (string) $user->id,
            'name' => $user->name,
        ]);
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
