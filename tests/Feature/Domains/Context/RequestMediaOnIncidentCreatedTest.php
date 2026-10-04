<?php

namespace Tests\Feature\Domains\Context;

use App\Domains\Assets\Models\Asset;
use App\Domains\Context\Enums\MediaRequestStatus;
use App\Domains\Context\Enums\MediaRequestType;
use App\Domains\Context\Jobs\FetchDeferredEventMediaJob;
use App\Domains\Context\Listeners\RequestIncidentMediaOnContextBuilt;
use App\Domains\Context\Listeners\RequestMediaOnIncidentCreated;
use App\Domains\Context\Models\EventMediaRequest;
use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\TenantConfig\Enums\SettingGroup;
use App\Domains\TenantConfig\Enums\SettingValueType;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Red de seguridad: ningún incidente abierto desde un evento se queda sin que
 * alguien le pida su media a la cámara, abra el incidente quien lo abra
 * (decisión de IA, fast path de emergencia, regla manual).
 */
class RequestMediaOnIncidentCreatedTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->team = User::factory()->create()->currentTeam;
    }

    private function enableAutoRequest(Team $team): void
    {
        TenantSetting::factory()->create([
            'team_id' => $team->id,
            'setting_key' => RequestIncidentMediaOnContextBuilt::SETTING_KEY,
            'setting_group' => SettingGroup::Operational,
            'value_json' => ['value' => true],
            'value_type' => SettingValueType::Boolean,
        ]);
    }

    private function incidentFor(Team $team, bool $withAsset = true, bool $withEvent = true): Incident
    {
        $event = $withEvent ? NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'asset_id' => $withAsset ? Asset::factory()->create(['team_id' => $team->id])->id : null,
        ]) : null;

        return Incident::factory()->open()->create([
            'team_id' => $team->id,
            'related_event_id' => $event?->id,
            'asset_id' => $event?->asset_id,
        ]);
    }

    private function react(Incident $incident): void
    {
        app(RequestMediaOnIncidentCreated::class)->react(new IncidentCreated($incident));
    }

    public function test_opens_a_sweep_only_request_for_the_incident_event(): void
    {
        $this->enableAutoRequest($this->team);
        $incident = $this->incidentFor($this->team);

        $this->react($incident);

        $request = EventMediaRequest::withoutGlobalScopes()->sole();
        $this->assertSame($incident->related_event_id, $request->normalized_event_id);
        $this->assertSame($this->team->id, $request->team_id);
        $this->assertSame(MediaRequestType::FetchVideoClip, $request->request_type);
        $this->assertTrue($request->sweep_only);

        Queue::assertPushed(FetchDeferredEventMediaJob::class, fn (FetchDeferredEventMediaJob $job) => $job->eventMediaRequestId === $request->id);
        $this->assertSystemLogged('context.media.requested');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_does_not_duplicate_a_request_the_context_already_opened_even_if_it_closed(): void
    {
        $this->enableAutoRequest($this->team);
        $incident = $this->incidentFor($this->team);

        EventMediaRequest::factory()->create([
            'team_id' => $this->team->id,
            'normalized_event_id' => $incident->related_event_id,
            'sweep_only' => true,
            'status' => MediaRequestStatus::Completed,
        ]);

        $this->react($incident);

        $this->assertSame(1, EventMediaRequest::withoutGlobalScopes()->count());
        Queue::assertNotPushed(FetchDeferredEventMediaJob::class);
        $c = $this->assertSystemLogged('context.media.auto_request_skipped', fn (array $c) => $c['reason'] === 'already_requested');
        $this->assertSame('incident_created', $c['input']['trigger']);
        $this->assertSame($incident->id, $c['input']['incident_id']);
    }

    public function test_skips_a_manual_incident_without_an_event(): void
    {
        $this->enableAutoRequest($this->team);

        $this->react($this->incidentFor($this->team, withEvent: false));

        $this->assertSame(0, EventMediaRequest::withoutGlobalScopes()->count());
        $this->assertSystemLogged('context.media.auto_request_skipped', fn (array $c) => $c['reason'] === 'no_related_event');
    }

    public function test_skips_an_event_without_a_resolved_asset(): void
    {
        $this->enableAutoRequest($this->team);

        $this->react($this->incidentFor($this->team, withAsset: false));

        $this->assertSame(0, EventMediaRequest::withoutGlobalScopes()->count());
        $this->assertSystemLogged('context.media.auto_request_skipped', fn (array $c) => $c['reason'] === 'asset_unresolved'
            && $c['input']['trigger'] === 'incident_created');
    }

    public function test_respects_the_tenant_switch(): void
    {
        $this->react($this->incidentFor($this->team));

        $this->assertSame(0, EventMediaRequest::withoutGlobalScopes()->count());
        $this->assertSystemLogged('context.media.auto_request_skipped', fn (array $c) => $c['reason'] === 'setting_disabled');
    }

    public function test_never_touches_another_tenant(): void
    {
        $other = User::factory()->create()->currentTeam;
        $this->enableAutoRequest($other);
        $this->incidentFor($other);

        $this->enableAutoRequest($this->team);
        $incident = $this->incidentFor($this->team);

        $this->assertNoTenantLeak($this->team, fn () => $this->react($incident));

        $this->assertSame([$this->team->id], EventMediaRequest::withoutGlobalScopes()->pluck('team_id')->all());
    }

    public function test_an_incident_pointing_at_another_tenants_event_never_requests_its_media(): void
    {
        $other = User::factory()->create()->currentTeam;
        $this->enableAutoRequest($other);
        $this->enableAutoRequest($this->team);

        $foreignEvent = NormalizedEvent::factory()->create([
            'team_id' => $other->id,
            'asset_id' => Asset::factory()->create(['team_id' => $other->id])->id,
        ]);

        $incident = Incident::factory()->open()->create([
            'team_id' => $this->team->id,
            'related_event_id' => $foreignEvent->id,
        ]);

        $this->assertNoTenantLeak($this->team, fn () => $this->react($incident));

        $this->assertSame(0, EventMediaRequest::withoutGlobalScopes()->count());
        Queue::assertNotPushed(FetchDeferredEventMediaJob::class);
        $this->assertSystemLogged('context.media.auto_request_skipped', fn (array $c) => $c['reason'] === 'normalized_event_missing'
            && $c['input']['incident_id'] === $incident->id);
    }

    public function test_retries_on_the_context_queue(): void
    {
        $this->assertSame('context', app(RequestMediaOnIncidentCreated::class)->retryQueue());
    }
}
