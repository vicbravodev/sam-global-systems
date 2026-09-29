<?php

namespace Tests\Feature\Support;

use App\Support\SystemLog;
use App\Support\SystemLogSchemaViolation;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class SystemLogTest extends TestCase
{
    use AssertsSystemLog;

    public function test_ok_writes_the_fixed_schema_at_info(): void
    {
        SystemLog::ok('ai.risk.calculated', input: ['severity' => 'high'], calc: ['base' => 0.5, 'final' => 0.65], result: ['priority' => 'high'], durationMs: 12);

        $ctx = $this->assertSystemLogged('ai.risk.calculated');

        $this->assertSame([
            'outcome' => 'ok',
            'input' => ['severity' => 'high'],
            'calc' => ['base' => 0.5, 'final' => 0.65],
            'result' => ['priority' => 'high'],
            'duration_ms' => 12,
        ], $ctx);
        $this->assertSame('info', $this->systemLogEntries('ai.risk.calculated')[0]['level']);
    }

    public function test_level_follows_the_outcome_and_debug_only_lowers_ok_and_skipped(): void
    {
        SystemLog::skipped('ai.gate.skipped', reason: 'skip_category', debug: true);
        SystemLog::degraded('ai.evaluation.rules_only', reason: 'quota_exceeded', debug: true);
        SystemLog::failed('queue.job.failed', reason: 'exception');

        $this->assertSame('debug', $this->systemLogEntries('ai.gate.skipped')[0]['level']);
        $this->assertSame('warning', $this->systemLogEntries('ai.evaluation.rules_only')[0]['level']);
        $this->assertSame('error', $this->systemLogEntries('queue.job.failed')[0]['level']);
        $this->assertSame('skip_category', $this->systemLogEntries('ai.gate.skipped')[0]['context']['reason']);
    }

    public function test_errors_are_described_safely(): void
    {
        SystemLog::degraded('media.download.failed', reason: 'http_error', error: new RuntimeException('GET https://x.s3.amazonaws.com/a.jpg?sig=1 → 403'));

        $ctx = $this->assertSystemLogged('media.download.failed');

        $this->assertSame(RuntimeException::class, $ctx['error']['class']);
        $this->assertSame('GET https://x.s3.amazonaws.com/a.jpg?[redacted] → 403', $ctx['error']['message']);
    }

    public function test_invalid_codes_and_missing_reasons_throw_outside_production(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SystemLog::ok('Media download ok');
    }

    public function test_missing_reason_throws_outside_production(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SystemLog::skipped('ai.gate.skipped', reason: '');
    }

    public function test_in_production_a_schema_violation_is_still_written_and_flagged(): void
    {
        $this->app['env'] = 'production';

        SystemLog::ok('Bad Code');

        $this->assertTrue($this->systemLogEntries('Bad Code')[0]['context']['schema_violation']);
    }

    public function test_measure_records_duration_and_rethrows_failures(): void
    {
        $this->assertSame(7, SystemLog::measure('samsara.api.request', fn () => 7, input: ['path' => '/fleet']));
        $ctx = $this->assertSystemLogged('samsara.api.request', fn (array $c) => $c['outcome'] === 'ok');
        $this->assertIsInt($ctx['duration_ms']);

        try {
            SystemLog::measure('samsara.api.request', fn () => throw new RuntimeException('boom'));
            $this->fail('should rethrow');
        } catch (RuntimeException) {
            $this->assertSystemLogged('samsara.api.request', fn (array $c) => $c['outcome'] === 'failed' && $c['reason'] === 'exception');
        }
    }

    public function test_a_broken_sink_never_propagates_but_a_schema_violation_still_throws(): void
    {
        SystemLog::listen(fn () => throw new RuntimeException('sink down'));

        SystemLog::ok('ai.gate.passed');
        SystemLog::failed('queue.job.failed', reason: 'exception', error: new RuntimeException('x'));

        $this->assertSystemLogged('ai.gate.passed');

        $this->expectException(SystemLogSchemaViolation::class);

        SystemLog::ok('Bad Code');
    }

    public function test_measure_rejects_an_invalid_code_before_running_the_callback(): void
    {
        $ran = false;

        try {
            SystemLog::measure('Bad Code', function () use (&$ran): void {
                $ran = true;

                throw new RuntimeException('callback error');
            });
            $this->fail('should throw');
        } catch (SystemLogSchemaViolation) {
            $this->assertFalse($ran);
        }
    }

    public function test_capture_works_even_when_every_event_is_faked(): void
    {
        Event::fake();

        SystemLog::ok('ai.gate.passed');

        $this->assertSystemLogged('ai.gate.passed');
    }

    public function test_no_sensitive_data_assertion_catches_raw_values_passed_to_system_log(): void
    {
        SystemLog::skipped('notifications.inbound_reply.ignored', reason: 'no_keyword', input: ['from' => '+525512345678']);

        $this->expectException(AssertionFailedError::class);

        $this->assertNoSensitiveDataLogged();
    }
}
