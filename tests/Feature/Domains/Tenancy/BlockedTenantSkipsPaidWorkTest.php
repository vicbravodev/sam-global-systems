<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\AI\Actions\EvaluateEventWithAI;
use App\Domains\AI\Jobs\EvaluateEventJob;
use App\Domains\AI\Listeners\EvaluateOnEventContextBuilt;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Support\AIEvaluationGate;
use App\Domains\Assets\Jobs\DispatchTelematicsFeedsJob;
use App\Domains\Assets\Jobs\FollowVehicleStatsFeedJob;
use App\Domains\Assets\Jobs\PollAllDeviceConnectivityJob;
use App\Domains\Assets\Jobs\PollAssetConnectivityJob;
use App\Domains\Context\Events\EventContextBuilt;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Context\Models\OperationalContextProfile;
use App\Domains\Incidents\Jobs\OpenEmergencyIncidentJob;
use App\Domains\Incidents\Listeners\OpenEmergencyIncidentOnEventNormalized;
use App\Domains\Ingestion\Jobs\PollSafetyEventsJob;
use App\Domains\Ingestion\Jobs\PollSamsaraSafetyEventsJob;
use App\Domains\Integrations\Jobs\SyncDueIntegrationsJob;
use App\Domains\Integrations\Jobs\SyncIntegrationJob;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\IntegrationSyncJob;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Normalization\Events\EventNormalized;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

/**
 * Un tenant que su suscripción bloquea (TenantCanSend: suspended, canceled,
 * expired) ya no se cobra, así que tampoco se sondea ni se evalúa con IA lo
 * que no es emergencia. Los pánicos siguen fluyendo.
 */
class BlockedTenantSkipsPaidWorkTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    private function team(?string $subscriptionState = null): Team
    {
        $team = User::factory()->create()->currentTeam;

        if ($subscriptionState !== null) {
            Subscription::factory()->{$subscriptionState}()->create(['team_id' => $team->id]);
        }

        return $team;
    }

    private function integration(Team $team): TenantIntegration
    {
        $provider = IntegrationProvider::query()->where('code', 'samsara')->first()
            ?? IntegrationProvider::factory()->samsara()->create();

        return TenantIntegration::factory()->active()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'last_sync_at' => now()->subHour(),
        ]);
    }

    // ── Feed de telemática ──────────────────────────────────────────────

    public function test_telematics_feed_skips_blocked_tenants_and_keeps_polling_the_rest(): void
    {
        $suspended = $this->integration($this->team('suspended'));
        $active = $this->integration($this->team('pastDue'));
        $noSubscription = $this->integration($this->team());

        (new DispatchTelematicsFeedsJob)->handle();

        $polled = Queue::pushed(FollowVehicleStatsFeedJob::class)
            ->map(fn (FollowVehicleStatsFeedJob $job) => $job->integration->id)
            ->unique()->sort()->values()->all();

        $this->assertSame([$active->id, $noSubscription->id], $polled);
        $this->assertNotContains($suspended->id, $polled);

        $context = $this->assertSystemLogged('telematics.feeds.dispatched', fn (array $c) => $c['outcome'] === 'ok');
        $this->assertSame(3, $context['result']['integrations_count']);
        $this->assertSame(1, $context['result']['tenant_blocked_count']);

        // Recorrido de plataforma: sólo conteos, nunca ids de tenant.
        $this->assertStringNotContainsString('team_id', (string) json_encode($this->systemLogEntries('telematics.feeds.dispatched')));
        $this->assertNoSensitiveDataLogged();
    }

    // ── Sondeo de safety events ─────────────────────────────────────────

    public function test_safety_events_poll_skips_a_blocked_tenant_without_stopping_others(): void
    {
        $blockedTeam = $this->team('canceled');
        $blocked = $this->integration($blockedTeam);
        $active = $this->integration($this->team('pastDue'));

        (new PollSamsaraSafetyEventsJob)->handle();

        Queue::assertPushed(PollSafetyEventsJob::class, 1);
        Queue::assertPushed(PollSafetyEventsJob::class, fn (PollSafetyEventsJob $job) => $job->integration->id === $active->id);

        $context = $this->assertSystemLogged('ingestion.safety_events_poll.skipped', fn (array $c) => $c['reason'] === 'tenant_blocked');
        $this->assertSame(['team_id' => $blockedTeam->id, 'integration_id' => $blocked->id], $context['input']);
        $this->assertSame('subscription_canceled', $context['calc']['blocked_reason']);
        $this->assertFalse($context['result']['dispatched']);
        $this->assertCount(1, $this->systemLogEntries('ingestion.safety_events_poll.skipped'));
        $this->assertNoSensitiveDataLogged();
    }

    // ── Sondeo de conectividad de dispositivos ──────────────────────────

    public function test_device_connectivity_poll_skips_a_blocked_tenant_without_stopping_others(): void
    {
        $first = $this->integration($this->team('pastDue'));
        $blockedTeam = $this->team('suspended');
        $blocked = $this->integration($blockedTeam);
        $last = $this->integration($this->team());

        (new PollAllDeviceConnectivityJob)->handle();

        $polled = Queue::pushed(PollAssetConnectivityJob::class)
            ->map(fn (PollAssetConnectivityJob $job) => $job->integration->id)
            ->sort()->values()->all();

        $this->assertSame([$first->id, $last->id], $polled);

        $skipped = $this->assertSystemLogged('assets.connectivity.skipped', fn (array $c) => $c['reason'] === 'tenant_blocked');
        $this->assertSame(['team_id' => $blockedTeam->id, 'integration_id' => $blocked->id], $skipped['input']);
        $this->assertSame('subscription_suspended', $skipped['calc']['blocked_reason']);
        $this->assertFalse($skipped['result']['dispatched']);
        $this->assertCount(1, $this->systemLogEntries('assets.connectivity.skipped'));

        $dispatched = $this->assertSystemLogged('assets.connectivity.dispatched');
        $this->assertSame(['dispatched_count' => 2, 'sync_disabled_count' => 0, 'tenant_blocked_count' => 1], $dispatched['result']);
        // Recorrido de plataforma: sólo conteos, nunca ids de tenant.
        $this->assertStringNotContainsString('team_id', (string) json_encode($this->systemLogEntries('assets.connectivity.dispatched')));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_device_connectivity_poll_still_polls_an_active_tenant(): void
    {
        $team = $this->team();
        Subscription::factory()->create(['team_id' => $team->id]); // estado por defecto: active
        $active = $this->integration($team);

        (new PollAllDeviceConnectivityJob)->handle();

        Queue::assertPushed(PollAssetConnectivityJob::class, fn (PollAssetConnectivityJob $job) => $job->integration->id === $active->id);
        $this->assertSame([], $this->systemLogEntries('assets.connectivity.skipped'));
    }

    // ── Sync programado del catálogo ────────────────────────────────────

    public function test_due_catalog_sync_skips_a_blocked_tenant_and_syncs_the_active_one(): void
    {
        $blockedTeam = $this->team('expired');
        $blocked = $this->integration($blockedTeam);
        $active = $this->integration($this->team('pastDue'));

        (new SyncDueIntegrationsJob)->handle();

        Queue::assertPushed(SyncIntegrationJob::class, 1);
        $this->assertSame(0, IntegrationSyncJob::query()->where('tenant_integration_id', $blocked->id)->count());
        $syncJob = IntegrationSyncJob::query()->where('tenant_integration_id', $active->id)->sole();

        $skipped = $this->assertSystemLogged('integrations.due_sync.skipped', fn (array $c) => $c['reason'] === 'tenant_blocked');
        $this->assertSame(['team_id' => $blockedTeam->id, 'integration_id' => $blocked->id], $skipped['input']);
        $this->assertSame('subscription_expired', $skipped['calc']['blocked_reason']);

        $requested = $this->assertSystemLogged('integrations.due_sync.requested', fn (array $c) => $c['input']['integration_id'] === $active->id);
        $this->assertSame(['integration_sync_job_id' => $syncJob->id, 'type' => 'incremental'], $requested['result']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_due_catalog_sync_narrates_not_due_and_in_flight_in_debug(): void
    {
        $notDue = $this->integration($this->team());
        $notDue->forceFill(['last_sync_at' => now()])->save();

        (new SyncDueIntegrationsJob)->handle();

        Queue::assertNotPushed(SyncIntegrationJob::class);
        $entry = $this->systemLogEntries('integrations.due_sync.skipped')[0];
        $this->assertSame('not_due', $entry['context']['reason']);
        $this->assertSame('debug', $entry['level']);
    }

    // ── Evaluación con IA ───────────────────────────────────────────────

    private function event(Team $team, string $categoryCode, ?string $typeCode = null): NormalizedEvent
    {
        $category = EventCategory::query()->where('code', $categoryCode)->first()
            ?? EventCategory::factory()->state(['code' => $categoryCode, 'name' => ucfirst($categoryCode)])->create();

        $type = EventType::factory()->create(array_filter([
            'category_id' => $category->id,
            'code' => $typeCode,
        ]));

        return NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'event_category_id' => $category->id,
            'event_type_id' => $type->id,
        ]);
    }

    private function contextBuilt(NormalizedEvent $event): EventContextBuilt
    {
        return new EventContextBuilt(
            EventContextSnapshot::factory()->create(['team_id' => $event->team_id, 'normalized_event_id' => $event->id]),
            OperationalContextProfile::factory()->create(['team_id' => $event->team_id]),
        );
    }

    public function test_ai_is_not_requested_for_a_non_emergency_event_of_a_blocked_tenant(): void
    {
        $blocked = $this->event($this->team('suspended'), 'operational');
        $active = $this->event($this->team(), 'operational');

        app(EvaluateOnEventContextBuilt::class)->handle($this->contextBuilt($blocked));
        app(EvaluateOnEventContextBuilt::class)->handle($this->contextBuilt($active));

        Queue::assertPushed(EvaluateEventJob::class, 1);
        Queue::assertPushed(EvaluateEventJob::class, fn (EvaluateEventJob $job) => $job->normalizedEventId === $active->id);

        $context = $this->assertSystemLogged('ai.gate.skipped', fn (array $c) => $c['reason'] === 'tenant_blocked');
        $this->assertSame($blocked->id, $context['input']['normalized_event_id']);
        $this->assertSame('context_listener', $context['input']['stage']);
        $this->assertSame('operational', $context['input']['category_code']);
        $this->assertSame(['blocked_reason' => 'subscription_suspended', 'is_emergency' => false], $context['calc']);
        $this->assertSame(['evaluated' => false, 'decision_engine_runs' => false], $context['result']);
        $this->assertCount(1, $this->systemLogEntries('ai.gate.skipped'));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_evaluate_job_creates_no_evaluation_for_a_blocked_tenant(): void
    {
        $this->seed(AIMeterSeeder::class);
        $event = $this->event($this->team('expired'), 'operational');

        (new EvaluateEventJob($event->id))->handle(app(EvaluateEventWithAI::class), app(AIEvaluationGate::class));

        $this->assertSame(0, AIEventEvaluation::withoutGlobalScopes()->where('normalized_event_id', $event->id)->count());
        $this->assertSystemLogged('ai.gate.skipped', fn (array $c) => $c['reason'] === 'tenant_blocked' && $c['input']['stage'] === 'evaluate_job');
    }

    public function test_an_emergency_of_a_blocked_tenant_is_still_evaluated_and_opens_its_incident(): void
    {
        $this->seed(AIMeterSeeder::class);
        $team = $this->team('suspended');
        $panic = $this->event($team, 'emergency', 'panic_button');

        // Carril rápido: el incidente se pide sin esperar IA.
        app(OpenEmergencyIncidentOnEventNormalized::class)->handle(new EventNormalized($panic));
        Queue::assertPushed(OpenEmergencyIncidentJob::class, fn (OpenEmergencyIncidentJob $job) => $job->normalizedEventId === $panic->id);

        // La IA sí se pide y se ejecuta.
        app(EvaluateOnEventContextBuilt::class)->handle($this->contextBuilt($panic));
        Queue::assertPushed(EvaluateEventJob::class, fn (EvaluateEventJob $job) => $job->normalizedEventId === $panic->id);

        (new EvaluateEventJob($panic->id))->handle(app(EvaluateEventWithAI::class), app(AIEvaluationGate::class));

        $this->assertSame(1, AIEventEvaluation::withoutGlobalScopes()->where('normalized_event_id', $panic->id)->count());
        $this->assertSystemNotLogged('ai.gate.skipped');
    }
}
