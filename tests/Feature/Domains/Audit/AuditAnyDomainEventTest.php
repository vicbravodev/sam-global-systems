<?php

namespace Tests\Feature\Domains\Audit;

use App\Domains\AI\Events\AIReevaluationRequested;
use App\Domains\Audit\Jobs\WriteAuditLogJob;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Audit\Models\DomainEventLog;
use App\Domains\Normalization\Events\EventNormalized;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Events\UsageRecorded;
use App\Models\Team;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class AuditAnyDomainEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_listener_dispatches_job_for_allowlisted_event(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $this->actingAs($user);

        $normalized = NormalizedEvent::factory()->create([
            'team_id' => $user->currentTeam->id,
        ]);

        EventNormalized::dispatch($normalized);

        Bus::assertDispatched(WriteAuditLogJob::class, function (WriteAuditLogJob $job) use ($user, $normalized) {
            return $job->eventName === EventNormalized::class
                && $job->teamId === $user->currentTeam->id
                && $job->aggregateType === NormalizedEvent::class
                && $job->aggregateId === $normalized->id;
        });
    }

    public function test_listener_ignores_non_allowlisted_event(): void
    {
        Bus::fake();

        AuditAnyDomainEventTestUnknownEvent::dispatch('payload');

        Bus::assertNotDispatched(WriteAuditLogJob::class);
    }

    public function test_listener_ignores_framework_events(): void
    {
        Bus::fake();

        // A non-`App\Domains\` event must short-circuit before reaching the
        // classifier. Triggering an Eloquent retrieved event proves the
        // wildcard listener does not flood the audit pipeline with noise.
        $user = User::factory()->create();
        $this->actingAs($user);

        Bus::assertNotDispatched(WriteAuditLogJob::class);
    }

    public function test_persisting_an_allowlisted_event_creates_audit_and_event_logs(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        UsageRecorded::dispatch($user->currentTeam->id, 'api_requests', 1, 'evt-1');

        $this->assertSame(1, AuditLog::withoutGlobalScopes()
            ->where('team_id', $user->currentTeam->id)
            ->where('action', 'tenancy.usage_recorded')
            ->count());

        $this->assertSame(1, DomainEventLog::withoutGlobalScopes()
            ->where('team_id', $user->currentTeam->id)
            ->where('event_name', UsageRecorded::class)
            ->count());
    }

    public function test_dispatching_the_same_event_twice_is_idempotent(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        UsageRecorded::dispatch($user->currentTeam->id, 'api_requests', 1, 'evt-1');
        UsageRecorded::dispatch($user->currentTeam->id, 'api_requests', 1, 'evt-1');

        // Two domain_event_logs (raw audit trail, append-only by definition)
        // but only ONE audit_logs row thanks to (team_id, signature) unique.
        $this->assertSame(1, AuditLog::withoutGlobalScopes()
            ->where('team_id', $user->currentTeam->id)
            ->where('action', 'tenancy.usage_recorded')
            ->count());
    }

    public function test_system_level_event_stays_platform_wide_even_inside_a_tenant(): void
    {
        $team = Team::factory()->create();

        $job = new WriteAuditLogJob(
            eventName: 'App\\Domains\\Platform\\Events\\Something',
            action: 'platform.something',
            category: 'domain',
            teamId: null,
            aggregateType: null,
            aggregateId: null,
            payloadJson: [],
            signature: 'platform-sig-1',
        );

        // El job hereda el TenantContext de quien lo despachó.
        TenantContext::for($team, fn () => $this->app->call([$job, 'handle']));

        $this->assertSame(0, AuditLog::withoutGlobalScopes()->where('team_id', $team->id)->count());
        $this->assertSame(0, DomainEventLog::withoutGlobalScopes()->where('team_id', $team->id)->count());
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->whereNull('team_id')->where('action', 'platform.something')->count());
        $this->assertSame(1, DomainEventLog::withoutGlobalScopes()->whereNull('team_id')->count());
    }

    public function test_reevaluation_requested_is_audited_under_the_events_team(): void
    {
        $normalized = NormalizedEvent::factory()->create();

        AIReevaluationRequested::dispatch($normalized->team_id, $normalized->id, 'manual_review_requested');

        $this->assertSame(1, AuditLog::withoutGlobalScopes()
            ->where('team_id', $normalized->team_id)
            ->where('action', 'ai.reevaluation_requested')
            ->count());
    }
}

/**
 * Test-only event used to assert the listener ignores classes that are
 * not in the audit allowlist.
 */
final class AuditAnyDomainEventTestUnknownEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly string $payload) {}
}
