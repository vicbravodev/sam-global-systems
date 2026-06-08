<?php

namespace Database\Seeders;

use App\Domains\Access\Models\Role;
use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Enums\LocationSource;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use App\Domains\Assets\Models\AssetType;
use App\Domains\Drivers\Enums\AssignmentSource;
use App\Domains\Drivers\Enums\AssignmentType;
use App\Domains\Drivers\Enums\DriverStatus;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverAssignment;
use App\Domains\Incidents\Enums\EventRelationType;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\IncidentSourceType;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentEventLink;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Incidents\Models\IncidentStatus;
use App\Domains\Incidents\Models\IncidentType;
use App\Domains\Ingestion\Enums\EventSourceStatus;
use App\Domains\Ingestion\Enums\EventSourceType;
use App\Domains\Ingestion\Enums\RawEventStatus;
use App\Domains\Ingestion\Models\EventSource;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Integrations\Enums\IntegrationProviderStatus;
use App\Domains\Integrations\Enums\IntegrationProviderType;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Normalization\Enums\NormalizedEventStatus;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Tenancy\Enums\BillingCycle;
use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\Subscription;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Demo data for working on the SAM UI.
 *
 * Run: `php artisan db:seed --class=DemoSeeder`
 *
 * Idempotent: re-runs delete the team's transactional rows (assets, drivers,
 * events, incidents, notifications) and recreate them. Users and the team
 * itself are preserved across runs so logins stay stable.
 */
class DemoSeeder extends Seeder
{
    private const TEAM_SLUG = 'sam-demo';

    private const TEAM_NAME = 'SAM Demo Fleet';

    /**
     * @var array<int, array{email: string, name: string, rbac_role: string, team_role: TeamRole}>
     */
    private const DEMO_USERS = [
        ['email' => 'admin@sam.test', 'name' => 'Ana Demo (Admin)', 'rbac_role' => 'tenant_admin', 'team_role' => TeamRole::Owner],
        ['email' => 'supervisor@sam.test', 'name' => 'Sergio Supervisor', 'rbac_role' => 'supervisor', 'team_role' => TeamRole::Admin],
        ['email' => 'monitor@sam.test', 'name' => 'María Monitorista', 'rbac_role' => 'monitorista', 'team_role' => TeamRole::Member],
        ['email' => 'analyst@sam.test', 'name' => 'Andrés Analyst', 'rbac_role' => 'analyst', 'team_role' => TeamRole::Member],
        ['email' => 'viewer@sam.test', 'name' => 'Valeria Viewer', 'rbac_role' => 'viewer', 'team_role' => TeamRole::Member],
    ];

    public function run(): void
    {
        $this->callPrerequisiteSeeders();

        DB::transaction(function () {
            $team = $this->createDemoTeam();
            $this->cleanTransactionalData($team);

            $owner = $this->createDemoUsers($team);
            $provider = $this->ensureSamsaraProvider();
            $eventSource = $this->createEventSource($team, $provider);

            $assets = $this->createAssets($team);
            $drivers = $this->createDrivers($team);
            $this->createDriverAssignments($team, $drivers, $assets);

            $events = $this->createEvents($team, $provider, $eventSource, $assets, $drivers);
            $this->createIncidents($team, $owner, $assets, $drivers, $events);
            $this->createNotifications($team, $owner);

            // Super-admin control-panel fixtures: plans, a global super-admin,
            // and a handful of extra tenants with varied subscription states.
            $this->createPlans();
            $this->createSuperAdmin();
            $this->createExtraTenants();
        });

        $this->command?->info("\nDemo data seeded for team [".self::TEAM_SLUG.'].');
        $this->command?->info('Login as admin@sam.test / password (other roles available, see DemoSeeder).');
        $this->command?->info('Super-admin: superadmin@sam.test / password → panel SaaS en /admin/tenants.');
    }

    private function callPrerequisiteSeeders(): void
    {
        $this->call(AccessSeeder::class);
        $this->call(AssetTypeSeeder::class);
        $this->call(AIMeterSeeder::class);
        $this->call(IncidentsSeeder::class);
        $this->call(NotificationMeterSeeder::class);
        $this->call(AutomationMeterSeeder::class);
        $this->call(DecisionOutcomeSeeder::class);

        $this->ensureSamsaraProvider();
        $this->ensureNormalizationCatalog();
    }

    /**
     * Seed the minimal set of EventCategory / EventSeverity / EventType rows the demo
     * needs. Kept inline (instead of calling NormalizationSeeder) so the demo does
     * not depend on the full Samsara mapping-rules pipeline.
     */
    private function ensureNormalizationCatalog(): void
    {
        $categories = [];
        foreach (['safety', 'emergency', 'compliance', 'operational', 'maintenance'] as $code) {
            $categories[$code] = EventCategory::query()->updateOrCreate(
                ['code' => $code],
                ['name' => Str::headline($code), 'description' => Str::headline($code).' events'],
            );
        }

        $severities = [];
        foreach ([
            ['code' => 'low', 'label' => 'Low', 'level' => 1, 'color' => '#22c55e', 'response_sla_seconds' => null],
            ['code' => 'medium', 'label' => 'Medium', 'level' => 2, 'color' => '#f59e0b', 'response_sla_seconds' => 3600],
            ['code' => 'high', 'label' => 'High', 'level' => 3, 'color' => '#f97316', 'response_sla_seconds' => 900],
            ['code' => 'critical', 'label' => 'Critical', 'level' => 4, 'color' => '#ef4444', 'response_sla_seconds' => 300],
        ] as $def) {
            $severities[$def['code']] = EventSeverity::query()->updateOrCreate(
                ['code' => $def['code']],
                $def,
            );
        }

        $types = [
            ['code' => 'panic_button', 'name' => 'Panic Button', 'category' => 'emergency', 'severity' => 'critical'],
            ['code' => 'collision', 'name' => 'Collision', 'category' => 'emergency', 'severity' => 'critical'],
            ['code' => 'harsh_braking', 'name' => 'Harsh Braking', 'category' => 'safety', 'severity' => 'medium'],
            ['code' => 'harsh_acceleration', 'name' => 'Harsh Acceleration', 'category' => 'safety', 'severity' => 'medium'],
            ['code' => 'speeding', 'name' => 'Speeding', 'category' => 'safety', 'severity' => 'medium'],
            ['code' => 'driver_fatigue', 'name' => 'Driver Fatigue', 'category' => 'safety', 'severity' => 'high'],
            ['code' => 'driver_distraction', 'name' => 'Driver Distraction', 'category' => 'safety', 'severity' => 'high'],
            ['code' => 'forward_collision_warning', 'name' => 'Forward Collision Warning', 'category' => 'safety', 'severity' => 'high'],
            ['code' => 'lane_departure', 'name' => 'Lane Departure', 'category' => 'safety', 'severity' => 'medium'],
            ['code' => 'mobile_usage', 'name' => 'Mobile Usage', 'category' => 'safety', 'severity' => 'high'],
            ['code' => 'no_seatbelt', 'name' => 'No Seatbelt', 'category' => 'compliance', 'severity' => 'medium'],
            ['code' => 'camera_obstructed', 'name' => 'Camera Obstructed', 'category' => 'compliance', 'severity' => 'high'],
            ['code' => 'geofence_exit', 'name' => 'Geofence Exit', 'category' => 'operational', 'severity' => 'low'],
            ['code' => 'vehicle_idle', 'name' => 'Vehicle Idle', 'category' => 'operational', 'severity' => 'low'],
        ];

        foreach ($types as $def) {
            EventType::query()->updateOrCreate(
                ['code' => $def['code']],
                [
                    'name' => $def['name'],
                    'category_id' => $categories[$def['category']]->id,
                    'default_severity_id' => $severities[$def['severity']]->id,
                    'is_active' => true,
                ],
            );
        }
    }

    private function createDemoTeam(): Team
    {
        return Team::query()->withTrashed()->updateOrCreate(
            ['slug' => self::TEAM_SLUG],
            [
                'name' => self::TEAM_NAME,
                'is_personal' => false,
                'timezone' => 'America/Mexico_City',
                'country' => 'MX',
                'currency' => 'mxn',
                'deleted_at' => null,
            ],
        );
    }

    private function cleanTransactionalData(Team $team): void
    {
        Notification::query()->where('team_id', $team->id)->delete();

        $incidentIds = Incident::query()->where('team_id', $team->id)->pluck('id');
        IncidentEventLink::query()->whereIn('incident_id', $incidentIds)->delete();
        Incident::query()->where('team_id', $team->id)->delete();

        NormalizedEvent::query()->where('team_id', $team->id)->delete();
        RawEvent::query()->where('team_id', $team->id)->delete();

        DriverAssignment::query()->where('team_id', $team->id)->delete();

        $assetIds = Asset::query()->withTrashed()->where('team_id', $team->id)->pluck('id');
        AssetLocationSnapshot::query()->whereIn('asset_id', $assetIds)->delete();
        Asset::query()->withTrashed()->where('team_id', $team->id)->forceDelete();

        Driver::query()->withTrashed()->where('team_id', $team->id)->forceDelete();

        EventSource::query()->where('team_id', $team->id)->delete();
    }

    private function createDemoUsers(Team $team): User
    {
        $owner = null;

        foreach (self::DEMO_USERS as $userData) {
            $user = User::query()->where('email', $userData['email'])->first();

            if (! $user) {
                $user = User::query()->create([
                    'name' => $userData['name'],
                    'email' => $userData['email'],
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ]);
            }

            $this->attachUserToTeam($user, $team, $userData['team_role'], $userData['rbac_role']);

            if ($userData['team_role'] === TeamRole::Owner) {
                $owner = $user;
            }
        }

        return $owner ?? User::query()->where('email', self::DEMO_USERS[0]['email'])->firstOrFail();
    }

    private function attachUserToTeam(User $user, Team $team, TeamRole $teamRole, string $rbacRoleCode): void
    {
        if (! $user->teams()->where('teams.id', $team->id)->exists()) {
            $team->members()->attach($user, ['role' => $teamRole->value]);
        } else {
            Membership::query()
                ->where('team_id', $team->id)
                ->where('user_id', $user->id)
                ->update(['role' => $teamRole->value]);
        }

        $role = Role::query()->where('code', $rbacRoleCode)->first();

        if ($role) {
            Membership::query()
                ->where('team_id', $team->id)
                ->where('user_id', $user->id)
                ->update(['role_id' => $role->id]);
        }

        $user->forceFill(['current_team_id' => $team->id])->save();
    }

    private function ensureSamsaraProvider(): IntegrationProvider
    {
        return IntegrationProvider::query()->updateOrCreate(
            ['code' => 'samsara'],
            [
                'name' => 'Samsara',
                'type' => IntegrationProviderType::Telematics,
                'status' => IntegrationProviderStatus::Active,
                'capabilities_json' => ['gps', 'diagnostics', 'driver_behavior'],
            ],
        );
    }

    private function createEventSource(Team $team, IntegrationProvider $provider): EventSource
    {
        return EventSource::query()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'source_type' => EventSourceType::Webhook,
            'source_name' => 'samsara-webhook-demo',
            'status' => EventSourceStatus::Active,
        ]);
    }

    /**
     * @return array<int, Asset>
     */
    private function createAssets(Team $team): array
    {
        $vehicleType = AssetType::query()->where('code', 'vehicle')->firstOrFail();

        $fleet = [
            ['name' => 'Truck T-101', 'code' => 'T-101', 'status' => AssetStatus::Active],
            ['name' => 'Truck T-102', 'code' => 'T-102', 'status' => AssetStatus::Active],
            ['name' => 'Truck T-103', 'code' => 'T-103', 'status' => AssetStatus::Active],
            ['name' => 'Truck T-104', 'code' => 'T-104', 'status' => AssetStatus::Active],
            ['name' => 'Van V-201', 'code' => 'V-201', 'status' => AssetStatus::Active],
            ['name' => 'Van V-202', 'code' => 'V-202', 'status' => AssetStatus::Active],
            ['name' => 'Van V-203', 'code' => 'V-203', 'status' => AssetStatus::Alert],
            ['name' => 'Van V-204', 'code' => 'V-204', 'status' => AssetStatus::Alert],
            ['name' => 'SUV S-301', 'code' => 'S-301', 'status' => AssetStatus::Active],
            ['name' => 'SUV S-302', 'code' => 'S-302', 'status' => AssetStatus::Critical],
            ['name' => 'Pickup P-401', 'code' => 'P-401', 'status' => AssetStatus::Maintenance],
            ['name' => 'Pickup P-402', 'code' => 'P-402', 'status' => AssetStatus::Offline],
        ];

        $assets = [];

        foreach ($fleet as $i => $data) {
            $asset = Asset::query()->create([
                'team_id' => $team->id,
                'asset_type_id' => $vehicleType->id,
                'name' => $data['name'],
                'code' => $data['code'],
                'status' => $data['status'],
                'metadata_json' => [
                    'vin' => '3HSDJAPR'.str_pad((string) ($i + 1), 8, '0', STR_PAD_LEFT),
                    'plate' => 'DEMO-'.str_pad((string) ($i + 100), 4, '0', STR_PAD_LEFT),
                    'make' => ['Freightliner', 'Ford', 'Chevrolet', 'Toyota'][$i % 4],
                    'model' => ['Cascadia', 'Transit', 'Silverado', 'Hilux'][$i % 4],
                    'year' => 2020 + ($i % 5),
                ],
                'first_seen_at' => now()->subMonths(rand(2, 18)),
                'last_seen_at' => now()->subMinutes(rand(0, 90)),
            ]);

            AssetLocationSnapshot::query()->create([
                'asset_id' => $asset->id,
                'latitude' => 19.3 + ((float) rand(0, 200) / 1000),
                'longitude' => -99.2 + ((float) rand(0, 200) / 1000),
                'speed' => $data['status'] === AssetStatus::Offline ? 0 : rand(0, 95),
                'heading' => rand(0, 359),
                'recorded_at' => now()->subMinutes(rand(0, 30)),
                'source' => LocationSource::Provider,
            ]);

            $assets[] = $asset;
        }

        return $assets;
    }

    /**
     * @return array<int, Driver>
     */
    private function createDrivers(Team $team): array
    {
        $drivers = [
            ['first' => 'Carlos', 'last' => 'Hernández', 'status' => DriverStatus::Active],
            ['first' => 'Lucía', 'last' => 'Ramírez', 'status' => DriverStatus::Active],
            ['first' => 'Miguel', 'last' => 'Torres', 'status' => DriverStatus::Active],
            ['first' => 'Patricia', 'last' => 'González', 'status' => DriverStatus::Active],
            ['first' => 'Roberto', 'last' => 'Vázquez', 'status' => DriverStatus::Active],
            ['first' => 'Sofía', 'last' => 'Méndez', 'status' => DriverStatus::Active],
            ['first' => 'Daniel', 'last' => 'Cruz', 'status' => DriverStatus::OffDuty],
            ['first' => 'Elena', 'last' => 'Flores', 'status' => DriverStatus::OffDuty],
            ['first' => 'Javier', 'last' => 'Morales', 'status' => DriverStatus::UnderReview],
            ['first' => 'Karla', 'last' => 'Salazar', 'status' => DriverStatus::Suspended],
        ];

        $created = [];

        foreach ($drivers as $i => $data) {
            $created[] = Driver::query()->create([
                'team_id' => $team->id,
                'first_name' => $data['first'],
                'last_name' => $data['last'],
                'full_name' => $data['first'].' '.$data['last'],
                'employee_code' => 'DRV-'.str_pad((string) ($i + 1001), 5, '0', STR_PAD_LEFT),
                'status' => $data['status'],
                'first_seen_at' => now()->subMonths(rand(3, 24)),
                'last_seen_at' => now()->subMinutes(rand(0, 240)),
            ]);
        }

        return $created;
    }

    /**
     * @param  array<int, Driver>  $drivers
     * @param  array<int, Asset>  $assets
     */
    private function createDriverAssignments(Team $team, array $drivers, array $assets): void
    {
        $pairs = min(count($drivers), count($assets));

        for ($i = 0; $i < $pairs - 2; $i++) {
            DriverAssignment::query()->create([
                'team_id' => $team->id,
                'driver_id' => $drivers[$i]->id,
                'asset_id' => $assets[$i]->id,
                'assignment_type' => AssignmentType::PrimaryDriver,
                'started_at' => now()->subDays(rand(7, 90)),
                'source' => AssignmentSource::Integration,
            ]);
        }
    }

    /**
     * @param  array<int, Asset>  $assets
     * @param  array<int, Driver>  $drivers
     * @return array<int, NormalizedEvent>
     */
    private function createEvents(Team $team, IntegrationProvider $provider, EventSource $eventSource, array $assets, array $drivers): array
    {
        $eventTypeCodes = ['speeding', 'harsh_braking', 'harsh_acceleration', 'driver_distraction', 'driver_fatigue', 'mobile_usage', 'no_seatbelt', 'panic_button', 'collision', 'forward_collision_warning', 'lane_departure', 'geofence_exit', 'vehicle_idle', 'camera_obstructed'];
        $eventTypes = EventType::query()->whereIn('code', $eventTypeCodes)->get()->keyBy('code');

        $created = [];

        for ($i = 0; $i < 35; $i++) {
            $code = $eventTypeCodes[array_rand($eventTypeCodes)];
            $eventType = $eventTypes[$code] ?? null;

            if (! $eventType) {
                continue;
            }

            $asset = $assets[array_rand($assets)];
            $driver = $drivers[array_rand($drivers)];
            $occurredAt = Carbon::now()->subMinutes(rand(5, 60 * 24 * 7));
            $payload = [
                'eventType' => $eventType->name,
                'eventId' => Str::uuid()->toString(),
                'eventTime' => $occurredAt->toIso8601String(),
                'data' => ['conditions' => [['description' => $eventType->name]]],
            ];

            $rawEvent = RawEvent::query()->create([
                'team_id' => $team->id,
                'event_source_id' => $eventSource->id,
                'provider_id' => $provider->id,
                'external_event_id' => $payload['eventId'],
                'event_type_raw' => $eventType->name,
                'payload_json' => $payload,
                'received_at' => $occurredAt,
                'occurred_at' => $occurredAt,
                'status' => RawEventStatus::Processed,
                'checksum' => hash('sha256', json_encode($payload)),
                'processing_attempts' => 1,
                'last_processing_attempt_at' => $occurredAt,
            ]);

            $created[] = NormalizedEvent::query()->create([
                'raw_event_id' => $rawEvent->id,
                'team_id' => $team->id,
                'provider_id' => $provider->id,
                'asset_id' => $asset->id,
                'driver_id' => $driver->id,
                'event_type_id' => $eventType->id,
                'event_category_id' => $eventType->category_id,
                'event_severity_id' => $eventType->default_severity_id,
                'occurred_at' => $occurredAt,
                'processed_at' => $occurredAt->copy()->addSeconds(2),
                'payload_normalized_json' => [
                    'event_type' => $eventType->code,
                    'description' => $eventType->name.' detected for '.$asset->name,
                    'speed' => rand(20, 110),
                ],
                'status' => NormalizedEventStatus::Enriched,
            ]);
        }

        return $created;
    }

    /**
     * @param  array<int, Asset>  $assets
     * @param  array<int, Driver>  $drivers
     * @param  array<int, NormalizedEvent>  $events
     */
    private function createIncidents(Team $team, User $owner, array $assets, array $drivers, array $events): void
    {
        $statuses = IncidentStatus::query()->get()->keyBy('code');
        $priorities = IncidentPriority::query()->get()->keyBy('code');
        $types = IncidentType::query()->get()->keyBy('code');

        $samples = [
            ['type' => 'panic_emergency', 'priority' => 'critical', 'status' => IncidentStatusCode::Open, 'title' => 'Panic button activated near downtown'],
            ['type' => 'collision', 'priority' => 'critical', 'status' => IncidentStatusCode::Escalated, 'title' => 'Collision detected on Highway 15'],
            ['type' => 'driver_fatigue', 'priority' => 'high', 'status' => IncidentStatusCode::InReview, 'title' => 'Driver fatigue alert (3rd this week)'],
            ['type' => 'camera_obstructed', 'priority' => 'medium', 'status' => IncidentStatusCode::Open, 'title' => 'Camera obstructed on Van V-203'],
            ['type' => 'route_deviation', 'priority' => 'medium', 'status' => IncidentStatusCode::InReview, 'title' => 'Vehicle off planned route'],
            ['type' => 'geofence_breach', 'priority' => 'high', 'status' => IncidentStatusCode::Open, 'title' => 'Asset exited authorized zone'],
            ['type' => 'suspicious_stop', 'priority' => 'medium', 'status' => IncidentStatusCode::Resolved, 'title' => 'Long unscheduled stop investigated'],
            ['type' => 'panic_emergency', 'priority' => 'critical', 'status' => IncidentStatusCode::Closed, 'title' => 'Panic button false positive (training)'],
        ];

        foreach ($samples as $i => $sample) {
            $type = $types[$sample['type']] ?? null;
            $priority = $priorities[$sample['priority']] ?? null;
            $status = $statuses[$sample['status']->value] ?? null;

            if (! $type || ! $priority || ! $status) {
                continue;
            }

            $event = $events[$i % max(count($events), 1)] ?? null;
            $opened = now()->subHours(rand(1, 96));

            $incident = Incident::query()->create([
                'team_id' => $team->id,
                'incident_type_id' => $type->id,
                'incident_status_id' => $status->id,
                'incident_priority_id' => $priority->id,
                'source_type' => $event ? IncidentSourceType::NormalizedEvent : IncidentSourceType::Manual,
                'source_reference_id' => $event?->id,
                'related_event_id' => $event?->id,
                'asset_id' => $assets[array_rand($assets)]->id,
                'driver_id' => $drivers[array_rand($drivers)]->id,
                'title' => $sample['title'],
                'summary' => $sample['title'].'. Generated by demo seeder for UI exploration.',
                'opened_at' => $opened,
                'resolved_at' => $sample['status']->isTerminal() ? $opened->copy()->addMinutes(rand(10, 120)) : null,
                'closed_at' => $sample['status'] === IncidentStatusCode::Closed ? $opened->copy()->addMinutes(rand(120, 360)) : null,
                'created_by_type' => $event ? IncidentCreatorType::System : IncidentCreatorType::User,
                'created_by_id' => $event ? null : $owner->id,
                'metadata_json' => ['demo' => true],
            ]);

            if ($event) {
                IncidentEventLink::query()->create([
                    'incident_id' => $incident->id,
                    'normalized_event_id' => $event->id,
                    'relation_type' => EventRelationType::RootTrigger,
                ]);
            }
        }
    }

    private function createNotifications(Team $team, User $owner): void
    {
        $samples = [
            ['type' => 'incident.created', 'priority' => NotificationPriority::Critical, 'status' => NotificationStatus::Sent, 'subject' => 'Critical: Panic button activated', 'preview' => 'Panic button activated on Truck T-101 near downtown.'],
            ['type' => 'incident.created', 'priority' => NotificationPriority::High, 'status' => NotificationStatus::Sent, 'subject' => 'Driver fatigue alert', 'preview' => 'Carlos Hernández flagged for fatigue on shift hour 9.'],
            ['type' => 'incident.status_changed', 'priority' => NotificationPriority::Normal, 'status' => NotificationStatus::Sent, 'subject' => 'Incident #3 moved to In Review', 'preview' => 'Driver fatigue case is now under review.'],
            ['type' => 'action.executed', 'priority' => NotificationPriority::Normal, 'status' => NotificationStatus::Sent, 'subject' => 'Workflow executed: Notify supervisor', 'preview' => 'Automation completed for incident #1.'],
            ['type' => 'incident.created', 'priority' => NotificationPriority::High, 'status' => NotificationStatus::Pending, 'subject' => 'Geofence breach', 'preview' => 'Van V-204 exited the warehouse perimeter.'],
        ];

        foreach ($samples as $i => $sample) {
            Notification::query()->create([
                'team_id' => $team->id,
                'source_type' => NotificationSourceType::Incident,
                'source_reference_id' => null,
                'notification_type' => $sample['type'],
                'priority' => $sample['priority'],
                'status' => $sample['status'],
                'subject' => $sample['subject'],
                'body_preview' => $sample['preview'],
                'triggered_by_type' => NotificationTriggeredByType::System,
                'triggered_by_id' => null,
                'event_key' => 'demo:'.$i.':'.Str::uuid()->toString(),
                'payload_json' => ['demo' => true],
                'sent_at' => $sample['status'] === NotificationStatus::Sent ? now()->subMinutes(rand(5, 240)) : null,
            ]);
        }
    }

    /**
     * Seed the catalog of plans surfaced in the super-admin "create tenant" form.
     */
    private function createPlans(): void
    {
        $defs = [
            ['code' => 'starter', 'name' => 'Starter', 'base_price' => 49.00],
            ['code' => 'pro', 'name' => 'Pro', 'base_price' => 199.00],
            ['code' => 'enterprise', 'name' => 'Enterprise', 'base_price' => 999.00],
        ];

        foreach ($defs as $def) {
            Plan::query()->updateOrCreate(
                ['code' => $def['code']],
                [
                    'name' => $def['name'],
                    'description' => $def['name'].' plan (demo).',
                    'base_price' => $def['base_price'],
                    'currency' => 'usd',
                    'billing_cycle' => BillingCycle::Monthly,
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * Seed a global super-admin (SaaS operator) with a personal team so that
     * exiting impersonation has somewhere to return to.
     */
    private function createSuperAdmin(): void
    {
        $user = User::query()->updateOrCreate(
            ['email' => 'superadmin@sam.test'],
            [
                'name' => 'Sam Super Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'global_role' => 'super_admin',
            ],
        );

        if (! $user->personalTeam()) {
            $personal = Team::query()->create([
                'name' => "Sam Super Admin's Team",
                'is_personal' => true,
            ]);
            $personal->members()->attach($user, ['role' => TeamRole::Owner->value]);
        }

        $user->forceFill(['current_team_id' => $user->personalTeam()->id])->save();
    }

    /**
     * A few non-personal tenants with varied subscription states so the panel
     * listing and the active/trial/past-due stats are meaningful.
     */
    private function createExtraTenants(): void
    {
        $plans = Plan::query()
            ->whereIn('code', ['starter', 'pro', 'enterprise'])
            ->get()
            ->keyBy('code');

        $specs = [
            ['slug' => 'acme-logistics', 'name' => 'Acme Logistics', 'owner' => 'owner.acme@sam.test', 'plan' => 'pro', 'status' => SubscriptionStatus::Active],
            ['slug' => 'globex-transport', 'name' => 'Globex Transport', 'owner' => 'owner.globex@sam.test', 'plan' => 'starter', 'status' => SubscriptionStatus::Trialing],
            ['slug' => 'initech-freight', 'name' => 'Initech Freight', 'owner' => 'owner.initech@sam.test', 'plan' => 'enterprise', 'status' => SubscriptionStatus::PastDue],
        ];

        foreach ($specs as $spec) {
            $team = Team::query()->withTrashed()->updateOrCreate(
                ['slug' => $spec['slug']],
                ['name' => $spec['name'], 'is_personal' => false, 'deleted_at' => null],
            );

            $owner = User::query()->updateOrCreate(
                ['email' => $spec['owner']],
                [
                    'name' => $spec['name'].' Owner',
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ],
            );

            if (! $owner->teams()->where('teams.id', $team->id)->exists()) {
                $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
            }

            if (! $owner->current_team_id) {
                $owner->forceFill(['current_team_id' => $team->id])->save();
            }

            Subscription::query()->updateOrCreate(
                ['team_id' => $team->id],
                [
                    'plan_id' => $plans[$spec['plan']]?->id,
                    'status' => $spec['status'],
                    'billing_cycle' => BillingCycle::Monthly,
                    'starts_at' => now()->subMonths(2),
                    'trial_ends_at' => $spec['status'] === SubscriptionStatus::Trialing ? now()->addDays(7) : null,
                    'renews_at' => now()->addMonth(),
                ],
            );
        }
    }
}
