<?php

namespace Tests\Feature\Domains\Context;

use App\Domains\Context\Enums\MediaRequestStatus;
use App\Domains\Context\Enums\MediaRequestType;
use App\Domains\Context\Events\EventContextBuilt;
use App\Domains\Context\Jobs\FetchDeferredEventMediaJob;
use App\Domains\Context\Listeners\RequestPanicMediaOnContextBuilt;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Context\Models\EventMediaRequest;
use App\Domains\Context\Models\OperationalContextProfile;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\TenantConfig\Enums\SettingGroup;
use App\Domains\TenantConfig\Enums\SettingValueType;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Models\User;
use Database\Seeders\ContextMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RequestPanicMediaOnContextBuiltTest extends TestCase
{
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
            'setting_key' => RequestPanicMediaOnContextBuilt::SETTING_KEY,
            'setting_group' => SettingGroup::Operational,
            'value_json' => ['value' => true],
            'value_type' => SettingValueType::Boolean,
        ]);
    }

    private function buildContext(string $severityCode = 'critical', bool $hasCamera = true): EventContextBuilt
    {
        $severity = EventSeverity::query()->firstOrCreate(
            ['code' => $severityCode],
            ['label' => ucfirst($severityCode), 'level' => $severityCode === 'critical' ? 4 : 2, 'color' => '#ef4444'],
        );

        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'event_severity_id' => $severity->id,
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
        app(RequestPanicMediaOnContextBuilt::class)->handle($event);
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

    public function test_never_requests_for_non_critical_events(): void
    {
        $this->enableAutoRequest();

        $this->handle($this->buildContext(severityCode: 'medium'));

        $this->assertSame(0, EventMediaRequest::withoutGlobalScopes()->count());
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
}
