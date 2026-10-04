<?php

namespace Tests\Feature\Domains\Context;

use App\Domains\Context\Enums\MediaRequestStatus;
use App\Domains\Context\Enums\MediaRequestType;
use App\Domains\Context\Events\EventContextBuilt;
use App\Domains\Context\Jobs\FetchDeferredEventMediaJob;
use App\Domains\Context\Listeners\RequestIncidentMediaOnContextBuilt;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Context\Models\EventMediaRequest;
use App\Domains\Context\Models\OperationalContextProfile;
use App\Domains\Assets\Models\Asset;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\TenantConfig\Enums\SettingGroup;
use App\Domains\TenantConfig\Enums\SettingValueType;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Models\User;
use Database\Seeders\ContextMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class RequestIncidentMediaOnContextBuiltTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->teamId = User::factory()->create()->currentTeam->id;
    }

    private function enableAutoRequest(?int $teamId = null): void
    {
        TenantSetting::factory()->create([
            'team_id' => $teamId ?? $this->teamId,
            'setting_key' => RequestIncidentMediaOnContextBuilt::SETTING_KEY,
            'setting_group' => SettingGroup::Operational,
            'value_json' => ['value' => true],
            'value_type' => SettingValueType::Boolean,
        ]);
    }

    private function buildContext(
        string $severityCode = 'critical',
        bool $hasCamera = true,
        string $categoryCode = 'compliance',
        ?string $eventTypeCode = null,
        bool $withAsset = true,
    ): EventContextBuilt {
        $severity = EventSeverity::query()->firstOrCreate(
            ['code' => $severityCode],
            ['label' => ucfirst($severityCode), 'level' => $severityCode === 'critical' ? 4 : 2, 'color' => '#ef4444'],
        );

        $category = EventCategory::query()->firstOrCreate(['code' => $categoryCode], ['name' => ucfirst($categoryCode)]);

        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'event_severity_id' => $severity->id,
            'event_category_id' => $category->id,
            'event_type_id' => EventType::factory()->create([
                'category_id' => $category->id,
                ...($eventTypeCode !== null ? ['code' => $eventTypeCode] : []),
            ])->id,
            'asset_id' => $withAsset ? Asset::factory()->create(['team_id' => $this->teamId])->id : null,
        ]);

        $snapshot = EventContextSnapshot::factory()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $event->id,
            'asset_snapshot_json' => ['has_camera' => $hasCamera],
        ]);

        $profile = OperationalContextProfile::factory()->create(['team_id' => $this->teamId]);

        return new EventContextBuilt($snapshot, $profile);
    }

    private function handle(EventContextBuilt $event): void
    {
        app(RequestIncidentMediaOnContextBuilt::class)->handle($event);
    }

    public function test_opens_a_single_sweep_only_request_for_critical_event_when_opted_in(): void
    {
        $this->enableAutoRequest();

        $event = $this->buildContext();

        $this->handle($event);

        // Panic footage is auto-uploaded by the dashcam: one sweep-only request
        // (never a paid retrieval) is enough to pull both clips and stills.
        $request = EventMediaRequest::withoutGlobalScopes()
            ->where('normalized_event_id', $event->snapshot->normalized_event_id)
            ->sole();

        $this->assertSame(MediaRequestType::FetchVideoClip, $request->request_type);
        $this->assertTrue($request->sweep_only);
        $this->assertSame(MediaRequestStatus::Pending, $request->status);
        $this->assertSame($this->teamId, $request->team_id);

        Queue::assertPushed(
            FetchDeferredEventMediaJob::class,
            fn (FetchDeferredEventMediaJob $job) => $job->eventMediaRequestId === $request->id,
        );
    }

    public function test_never_places_a_still_retrieval_request_for_panic(): void
    {
        $this->enableAutoRequest();

        // Even with a generous still count, the panic path stays sweep-only and
        // never opens a paid FetchSnapshot retrieval request.
        TenantSetting::factory()->create([
            'team_id' => $this->teamId,
            'setting_key' => FetchDeferredEventMediaJob::SETTING_STILL_COUNT,
            'setting_group' => SettingGroup::Operational,
            'value_json' => ['value' => 6],
            'value_type' => SettingValueType::Number,
        ]);

        $event = $this->buildContext();

        $this->handle($event);

        $this->assertSame(
            0,
            EventMediaRequest::withoutGlobalScopes()
                ->where('request_type', MediaRequestType::FetchSnapshot->value)
                ->count(),
        );
        $this->assertSame(1, EventMediaRequest::withoutGlobalScopes()->count());
    }

    public function test_does_nothing_by_default_because_auto_request_is_opt_in(): void
    {
        $this->handle($this->buildContext());

        $this->assertSame(0, EventMediaRequest::withoutGlobalScopes()->count());
        Queue::assertNotPushed(FetchDeferredEventMediaJob::class);
    }

    public function test_requests_for_non_critical_events_that_can_open_an_incident(): void
    {
        $this->enableAutoRequest();

        // Caso real: pasajero no autorizado (compliance, medium) abría
        // incidente por IA sin que nadie pidiera nunca una foto de la cabina.
        $event = $this->buildContext(severityCode: 'medium', categoryCode: 'compliance', eventTypeCode: 'unauthorized_passenger');

        $this->handle($event);

        $request = EventMediaRequest::withoutGlobalScopes()->sole();
        $this->assertSame($event->snapshot->normalized_event_id, $request->normalized_event_id);
        $this->assertTrue($request->sweep_only);
    }

    public function test_requests_for_an_emergency_whatever_its_severity(): void
    {
        $this->enableAutoRequest();

        $this->handle($this->buildContext(severityCode: 'high', categoryCode: 'emergency', eventTypeCode: 'panic_button'));

        $this->assertSame(1, EventMediaRequest::withoutGlobalScopes()->count());
    }

    public function test_never_requests_for_events_that_cannot_open_an_incident(): void
    {
        $this->enableAutoRequest();

        // `safety` está en ai.skip_evaluation_categories: no se evalúa ni abre incidente.
        $this->handle($this->buildContext(severityCode: 'medium', categoryCode: 'safety'));

        $this->assertSame(0, EventMediaRequest::withoutGlobalScopes()->count());
    }

    public function test_never_requests_for_a_skipped_event_type(): void
    {
        $this->enableAutoRequest();
        config(['ai.skip_evaluation_event_types' => ['geofence_entry']]);

        $this->handle($this->buildContext(severityCode: 'medium', categoryCode: 'operational', eventTypeCode: 'geofence_entry'));

        $this->assertSame(0, EventMediaRequest::withoutGlobalScopes()->count());
    }

    public function test_skips_and_logs_an_event_without_a_resolved_asset(): void
    {
        $this->enableAutoRequest();
        $event = $this->buildContext(withAsset: false);

        $this->handle($event);

        // Sin unidad no hay a qué cámara preguntarle.
        $this->assertSame(0, EventMediaRequest::withoutGlobalScopes()->count());
        $c = $this->assertSystemLogged('context.media.auto_request_skipped', fn (array $c) => $c['reason'] === 'asset_unresolved');
        $this->assertSame($event->snapshot->normalized_event_id, $c['input']['normalized_event_id']);
        $this->assertSame('context_built', $c['input']['trigger']);
    }

    public function test_camera_less_asset_still_opens_the_sweep_only_request(): void
    {
        $this->enableAutoRequest();

        $event = $this->buildContext(hasCamera: false);

        $this->handle($event);

        // The camera flag is irrelevant to the sweep-only request: the uploaded
        // media listing works regardless, and the flag can be stale anyway.
        $request = EventMediaRequest::withoutGlobalScopes()
            ->where('normalized_event_id', $event->snapshot->normalized_event_id)
            ->sole();

        $this->assertSame(MediaRequestType::FetchVideoClip, $request->request_type);
        $this->assertTrue($request->sweep_only);
        Queue::assertPushed(
            FetchDeferredEventMediaJob::class,
            fn (FetchDeferredEventMediaJob $job) => $job->eventMediaRequestId === $request->id,
        );
    }

    public function test_is_idempotent_across_context_rebuilds(): void
    {
        $this->enableAutoRequest();

        $event = $this->buildContext();

        $this->handle($event);
        $this->handle($event);

        // A single sweep-only request, no duplicates on rebuild.
        $this->assertSame(1, EventMediaRequest::withoutGlobalScopes()->count());
    }

    public function test_records_usage_once_per_request(): void
    {
        $this->seed(ContextMeterSeeder::class);
        $this->enableAutoRequest();

        $event = $this->buildContext();

        $this->handle($event);
        $this->handle($event);

        foreach (EventMediaRequest::withoutGlobalScopes()->get() as $request) {
            $usage = UsageEvent::withoutGlobalScopes()
                ->where('team_id', $this->teamId)
                ->where('event_key', "media_request:{$request->id}")
                ->count();

            $this->assertSame(1, $usage);
        }
    }

    public function test_setting_of_another_tenant_does_not_leak(): void
    {
        $otherTeamId = User::factory()->create()->currentTeam->id;
        $this->enableAutoRequest($otherTeamId);

        $this->handle($this->buildContext());

        $this->assertSame(0, EventMediaRequest::withoutGlobalScopes()->count());
    }

    public function test_logs_not_incident_worthy_skip(): void
    {
        $this->enableAutoRequest();
        $event = $this->buildContext(severityCode: 'medium', categoryCode: 'safety');

        $this->handle($event);

        $c = $this->assertSystemLogged('context.media.auto_request_skipped', fn (array $c) => $c['reason'] === 'not_incident_worthy');
        $this->assertSame($event->snapshot->normalized_event_id, $c['input']['normalized_event_id']);
        $this->assertSame('medium', $c['input']['severity_code']);
        $this->assertSame('safety', $c['input']['category_code']);
        $this->assertSame('skip_category', $c['calc']['gate_skip_reason']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_logs_setting_disabled_skip(): void
    {
        $event = $this->buildContext();

        $this->handle($event);

        $c = $this->assertSystemLogged('context.media.auto_request_skipped', fn (array $c) => $c['reason'] === 'setting_disabled');
        $this->assertSame(RequestIncidentMediaOnContextBuilt::SETTING_KEY, $c['input']['setting_key']);
    }

    public function test_logs_skip_when_event_not_found(): void
    {
        $event = $this->buildContext();
        NormalizedEvent::withoutGlobalScopes()->whereKey($event->snapshot->normalized_event_id)->delete();

        $this->handle($event);

        $c = $this->assertSystemLogged('context.media.auto_request_skipped', fn (array $c) => $c['reason'] === 'normalized_event_missing');
        $this->assertSame($event->snapshot->id, $c['input']['snapshot_id']);
    }

    public function test_logs_media_requested_for_critical_event_with_setting_enabled(): void
    {
        $this->enableAutoRequest();
        $event = $this->buildContext();

        $this->handle($event);

        $request = EventMediaRequest::withoutGlobalScopes()->sole();
        $c = $this->assertSystemLogged('context.media.requested');
        $this->assertSame($request->id, $c['result']['event_media_request_id']);
        $this->assertSame(6, $c['calc']['expires_in_hours']);
        $this->assertTrue($c['input']['sweep_only']);
        $this->assertNoSensitiveDataLogged();
    }
}
