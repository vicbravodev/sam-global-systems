<?php

namespace Tests\Feature\Seeders;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use App\Domains\Assets\Models\AssetType;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Incidents\Models\IncidentStatus;
use App\Domains\Incidents\Models\IncidentType;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Notifications\Channels\TwilioClientFactory;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Showcase\ShowcaseReplayer;
use Database\Seeders\Showcase\ShowcaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * `sam:showcase` sobre una DB recién sembrada (`migrate:fresh --seed`):
 * todas las pantallas renderizan con datos, una segunda corrida no
 * duplica, no toca filas que no son suyas, no escribe en otro tenant y
 * nunca saca nada del proceso (correo, SMS, llamadas, HTTP).
 */
class ShowcaseSeederTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    private const TEAM = 'serviexpress-jc';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('rustfs');
        $this->seed(DatabaseSeeder::class);
    }

    private function showcase(string $slug = self::TEAM, int $days = 5, bool $extraTenants = false): ShowcaseSeeder
    {
        $seeder = (new ShowcaseSeeder)->configure($slug, $days, $extraTenants);
        $seeder->setContainer($this->app)->__invoke();

        return $seeder;
    }

    private function admin(): User
    {
        return User::where('email', 'admin@serviexpress.test')->firstOrFail();
    }

    public function test_every_tenant_page_renders_with_data(): void
    {
        $this->showcase();

        $user = $this->admin();
        $team = Team::where('slug', self::TEAM)->firstOrFail();
        $base = '/'.self::TEAM;
        $nonEmpty = fn ($value) => $value !== null && count($value) > 0;

        $event = NormalizedEvent::withoutGlobalScopes()->where('team_id', $team->id)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('decisions')->whereColumn('decisions.normalized_event_id', 'normalized_events.id'))
            ->firstOrFail();
        $incident = Incident::withoutGlobalScopes()->where('team_id', $team->id)->whereNotNull('metadata_json->showcase_key')->firstOrFail();
        $asset = Asset::withoutGlobalScopes()->where('team_id', $team->id)->firstOrFail();
        $driver = Driver::withoutGlobalScopes()->where('team_id', $team->id)->firstOrFail();

        $pages = [
            "{$base}/dashboard" => ['dashboard', fn (AssertableInertia $p) => $p->where('incidents', $nonEmpty)->where('stream', $nonEmpty)->where('integrations', $nonEmpty)->where('usage', $nonEmpty)],
            "{$base}/events" => ['events/index', fn (AssertableInertia $p) => $p->where('events', $nonEmpty)],
            "{$base}/events/{$event->id}" => ['events/show', fn (AssertableInertia $p) => $p->whereNot('evaluation', null)->whereNot('decision', null)],
            "{$base}/incidents" => ['incidents/index', fn (AssertableInertia $p) => $p->where('incidents', $nonEmpty)],
            "{$base}/incidents/{$incident->id}" => ['incidents/show', fn (AssertableInertia $p) => $p->where('incident.timeline', $nonEmpty)->where('incident.evidence', $nonEmpty)],
            "{$base}/assets" => ['assets/index', fn (AssertableInertia $p) => $p->where('assets', $nonEmpty)],
            "{$base}/assets/map" => ['assets/map', fn (AssertableInertia $p) => $p->where('assets', $nonEmpty)],
            "{$base}/assets/{$asset->id}" => ['assets/show', fn (AssertableInertia $p) => $p->where('telemetry', fn ($t) => count($t) === 7)->where('locationHistory', $nonEmpty)],
            "{$base}/drivers" => ['drivers/index', fn (AssertableInertia $p) => $p->where('drivers', $nonEmpty)],
            "{$base}/drivers/{$driver->id}" => ['drivers/show', fn (AssertableInertia $p) => $p->where('driver.contacts', $nonEmpty)->where('driver.documents', $nonEmpty)->where('statusLog', $nonEmpty)->whereNot('driver.riskProfile', null)],
            "{$base}/integrations" => ['integrations/index', fn (AssertableInertia $p) => $p->where('integrations', fn ($i) => count($i) >= 3)],
            "{$base}/notifications" => ['notifications/index', fn (AssertableInertia $p) => $p->where('notifications', $nonEmpty)],
            "{$base}/automation" => ['automation/index', fn (AssertableInertia $p) => $p->where('workflows', $nonEmpty)->where('executions', $nonEmpty)],
            "{$base}/rules" => ['rules/index', fn (AssertableInertia $p) => $p->where('overrides', $nonEmpty)],
            "{$base}/audit" => ['audit/index', fn (AssertableInertia $p) => $p->where('logs', $nonEmpty)->where('events', $nonEmpty)],
            "{$base}/billing" => ['billing/index', fn (AssertableInertia $p) => $p->whereNot('subscription', null)->where('features', $nonEmpty)->where('usage', $nonEmpty)->where('invoices', fn ($i) => count($i) >= 3)],
            "{$base}/analytics" => ['analytics/index', fn (AssertableInertia $p) => $p->whereNot('overview', null)->where('kpis', $nonEmpty)->where('reports', $nonEmpty)->where('executions', $nonEmpty)],
            "{$base}/copilot" => ['copilot/index', fn (AssertableInertia $p) => $p->where('conversations', $nonEmpty)],
            "{$base}/copilot/usage?days=30" => ['copilot/usage', fn (AssertableInertia $p) => $p->has('usage')],
            "{$base}/settings/tenant-config" => ['settings/tenant-config', fn (AssertableInertia $p) => $p->whereNot('aiProfile', null)->where('notificationPolicies', $nonEmpty)->where('scheduleProfiles', $nonEmpty)->where('versions', $nonEmpty)],
            "{$base}/settings/tenant-config/slas" => ['settings/tenant-config/slas', fn (AssertableInertia $p) => $p->where('priorities', $nonEmpty)],
            '/settings/notifications' => ['settings/notifications', fn (AssertableInertia $p) => $p->where('preferences', $nonEmpty)],
            '/settings/teams/'.self::TEAM => ['teams/edit', fn (AssertableInertia $p) => $p->where('members', fn ($m) => count($m) > 2)->where('invitations', $nonEmpty)],
        ];

        foreach ($pages as $uri => [$component, $assert]) {
            $this->actingAs($user)
                ->get($uri)
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $assert($page->component($component)));
        }
    }

    public function test_admin_console_is_populated_with_extra_tenants(): void
    {
        $this->showcase(days: 3, extraTenants: true);

        $operator = User::where('email', 'superadmin@sam.test')->firstOrFail();
        $this->assertTrue($operator->isSuperAdmin());

        $this->actingAs($operator)->get('/admin/tenants')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('admin/tenants/index')->where('tenants', fn ($t) => count($t) >= 4));
        $this->actingAs($operator)->get('/admin/tenants/fletes-express-sur')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('admin/tenants/show')->where('invoices', fn ($i) => count($i) > 0)->where('subscription.status', 'past_due'));
        $this->actingAs($operator)->get('/admin/audit')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('admin/audit/index')->where('entries', fn ($e) => count($e) > 0));
    }

    public function test_a_second_run_does_not_duplicate_anything(): void
    {
        $this->showcase();
        $before = $this->rowCounts();

        $this->showcase();

        $this->assertSame($before, $this->rowCounts());
    }

    public function test_pre_existing_rows_are_left_untouched(): void
    {
        $team = Team::where('slug', self::TEAM)->firstOrFail();
        $asset = Asset::factory()->create(['team_id' => $team->id, 'asset_type_id' => AssetType::where('code', 'vehicle')->value('id'), 'name' => 'Unidad real']);
        AssetLocationSnapshot::factory()->create(['asset_id' => $asset->id, 'recorded_at' => now()->subHour()]);
        $panic = EventType::where('code', 'panic_button')->firstOrFail();
        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'asset_id' => $asset->id,
            'event_type_id' => $panic->id,
            'event_category_id' => $panic->category_id,
            'event_severity_id' => $panic->default_severity_id,
            'occurred_at' => now()->subDay(),
        ]);
        AIEventEvaluation::factory()->create(['team_id' => $team->id, 'normalized_event_id' => $event->id]);
        Incident::factory()->create([
            'team_id' => $team->id,
            'related_event_id' => $event->id,
            'asset_id' => $asset->id,
            'incident_type_id' => IncidentType::where('code', 'panic_emergency')->value('id'),
            'incident_status_id' => IncidentStatus::where('code', 'open')->value('id'),
            'incident_priority_id' => IncidentPriority::where('code', 'critical')->value('id'),
        ]);

        $snapshot = fn () => [
            'assets' => DB::table('assets')->where('id', $asset->id)->get()->toArray(),
            'locations' => DB::table('asset_location_snapshots')->where('asset_id', $asset->id)->get()->toArray(),
            'events' => DB::table('normalized_events')->where('id', $event->id)->get()->toArray(),
            'evaluations' => DB::table('ai_event_evaluations')->where('normalized_event_id', $event->id)->get()->toArray(),
            'incidents' => DB::table('incidents')->where('related_event_id', $event->id)->get()->toArray(),
            'users' => DB::table('users')->where('email', 'admin@serviexpress.test')->get()->toArray(),
            'plans' => DB::table('plans')->orderBy('id')->get()->toArray(),
        ];
        $before = $snapshot();

        $this->showcase();

        $this->assertEquals($before, $snapshot());
        // El showcase rellena huecos: no le añade trazas GPS ni otra evaluación a lo que ya las tenía.
        $this->assertSame(1, DB::table('asset_location_snapshots')->where('asset_id', $asset->id)->count());
        $this->assertSame(1, DB::table('ai_event_evaluations')->where('normalized_event_id', $event->id)->count());
        $this->assertSame(0, DB::table('incident_timelines')->whereIn('incident_id', DB::table('incidents')->where('related_event_id', $event->id)->pluck('id'))->count());
    }

    public function test_seeding_one_tenant_never_writes_to_another(): void
    {
        $team = Team::where('slug', self::TEAM)->firstOrFail();
        $other = Team::factory()->create(['slug' => 'otro-cliente']);
        Asset::factory()->create(['team_id' => $other->id, 'asset_type_id' => AssetType::where('code', 'vehicle')->value('id')]);
        NormalizedEvent::factory()->create(['team_id' => $other->id]);

        $this->assertNoTenantLeak($team, fn () => $this->showcase());

        $this->assertSame(1, Asset::withoutGlobalScopes()->where('team_id', $other->id)->count());
        $this->assertSame(0, Incident::withoutGlobalScopes()->where('team_id', $other->id)->count());
    }

    public function test_nothing_leaves_the_process(): void
    {
        Http::fake();

        $this->showcase();
        $result = app(ShowcaseReplayer::class)->replay(self::TEAM, 3);

        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Http::assertNothingSent();

        // Los jobs que hablan con Twilio/proveedores quedan capturados y NUNCA se ejecutan.
        $this->assertGreaterThan(0, $result['replayed']);
        $this->assertGreaterThan(0, $result['jobs']);
        $this->assertSame(0, DB::table('notification_deliveries')
            ->join('notifications', 'notifications.id', '=', 'notification_deliveries.notification_id')
            ->where('notifications.event_key', 'not like', 'showcase:%')
            ->count(), 'El replay no debe entregar notificaciones reales.');
        // Las notificaciones del replay se quedan en cola: su job de envío nunca corre.
        $this->assertSame(0, DB::table('notifications')->where('event_key', 'not like', 'showcase:%')->whereNotIn('status', ['pending', 'queued', 'cancelled'])->count());

        $this->expectException(RuntimeException::class);
        app(TwilioClientFactory::class);
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('sam:showcase')->assertFailed();
        $this->assertSame(0, DB::table('raw_events')->count());
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        $counts = [];

        foreach (Schema::getTables() as $table) {
            $name = $table['name'];

            if (in_array($name, ['migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'failed_jobs'], true)) {
                continue;
            }

            $counts[$name] = DB::table($name)->count();
        }

        return $counts;
    }
}
