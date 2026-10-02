<?php

namespace Tests\Feature\Domains\Audit;

use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Audit\Jobs\WriteAuditLogJob;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Audit\Models\DomainEventLog;
use App\Models\Team;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class WriteAuditLogJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_writes_the_domain_event_and_the_audit_entry(): void
    {
        $team = Team::factory()->create();

        $this->app->call([$this->job($team->id), 'handle']);

        $this->assertSame(1, DomainEventLog::withoutGlobalScopes()->where('team_id', $team->id)->count());
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('team_id', $team->id)->where('signature', 'sig-audit-1')->count());
    }

    public function test_a_retry_after_the_audit_step_failed_does_not_duplicate_the_domain_event(): void
    {
        $team = Team::factory()->create();

        // El segundo paso (audit_logs) falla en el primer intento.
        $failing = true;
        AuditLog::creating(function () use (&$failing): void {
            if ($failing) {
                throw new RuntimeException('audit insert failed');
            }
        });

        try {
            $this->app->call([$this->job($team->id), 'handle']);
            $this->fail('El primer intento debía fallar.');
        } catch (RuntimeException) {
            // esperado: la cola lo reintenta (tries = 3)
        }

        $this->assertSame(0, DomainEventLog::withoutGlobalScopes()->where('team_id', $team->id)->count(), 'El intento fallido no deja domain event');

        $failing = false;
        $this->app->call([$this->job($team->id), 'handle']);

        $this->assertSame(1, DomainEventLog::withoutGlobalScopes()->where('team_id', $team->id)->count());
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('team_id', $team->id)->count());
    }

    public function test_the_job_does_not_pretend_to_be_unique(): void
    {
        // uniqueId() sin ShouldBeUnique era código muerto: la idempotencia la
        // dan la transacción y la firma única de audit_logs.
        $job = $this->job(null);

        $this->assertNotInstanceOf(ShouldBeUnique::class, $job);
        $this->assertFalse(method_exists($job, 'uniqueId'));
    }

    private function job(?int $teamId): WriteAuditLogJob
    {
        return new WriteAuditLogJob(
            eventName: 'App\\Domains\\Incidents\\Events\\IncidentCreated',
            action: 'incident.created',
            category: AuditCategory::Domain->value,
            teamId: $teamId,
            aggregateType: 'App\\Domains\\Incidents\\Models\\Incident',
            aggregateId: 7,
            payloadJson: ['incident_id' => 7],
            signature: 'sig-audit-1',
        );
    }
}
