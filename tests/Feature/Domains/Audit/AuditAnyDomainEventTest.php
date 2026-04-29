<?php

namespace Tests\Feature\Domains\Audit;

use App\Domains\Audit\Jobs\WriteAuditLogJob;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Audit\Models\DomainEventLog;
use App\Domains\Normalization\Events\EventNormalized;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Events\UsageRecorded;
use App\Models\User;
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
