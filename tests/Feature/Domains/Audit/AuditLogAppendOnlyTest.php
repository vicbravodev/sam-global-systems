<?php

namespace Tests\Feature\Domains\Audit;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Audit\Models\ChangeHistory;
use App\Domains\Audit\Models\DomainEventLog;
use App\Domains\Audit\Models\SystemTrace;
use App\Domains\Audit\Models\TraceLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AuditLogAppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_cannot_be_updated(): void
    {
        $log = AuditLog::factory()->create();

        $this->expectException(RuntimeException::class);

        $log->summary = 'tampered summary';
        $log->save();
    }

    public function test_audit_log_cannot_be_deleted(): void
    {
        $log = AuditLog::factory()->create();

        $this->expectException(RuntimeException::class);

        $log->delete();
    }

    public function test_domain_event_log_cannot_be_updated(): void
    {
        $log = DomainEventLog::factory()->create();

        $this->expectException(RuntimeException::class);

        $log->event_name = 'Tampered';
        $log->save();
    }

    public function test_change_history_cannot_be_deleted(): void
    {
        $history = ChangeHistory::factory()->create();

        $this->expectException(RuntimeException::class);

        $history->delete();
    }

    public function test_system_trace_cannot_be_updated(): void
    {
        $trace = SystemTrace::factory()->create();

        $this->expectException(RuntimeException::class);

        $trace->module_name = 'tampered';
        $trace->save();
    }

    public function test_trace_link_cannot_be_deleted(): void
    {
        $link = TraceLink::factory()->create();

        $this->expectException(RuntimeException::class);

        $link->delete();
    }

    public function test_audit_log_has_no_updated_at_column(): void
    {
        $log = AuditLog::factory()->create();

        // The model defines `UPDATED_AT = null`, so the attribute is unset.
        $this->assertNull($log->getUpdatedAtColumn());
        $this->assertArrayNotHasKey('updated_at', $log->getAttributes());
    }
}
