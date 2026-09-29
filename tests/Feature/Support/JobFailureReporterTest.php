<?php

namespace Tests\Feature\Support;

use App\Support\JobFailureReporter;
use App\Support\SystemLog;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class JobFailureReporterTest extends TestCase
{
    use AssertsSystemLog;

    public function test_reports_at_error_level_with_context(): void
    {
        JobFailureReporter::report('App\Jobs\Fake', new RuntimeException('boom'), ['team_id' => 7]);

        $ctx = $this->assertSystemLogged('app.fake.failed');
        $this->assertCount(1, $this->systemLogEntries('app.fake.failed'));

        $this->assertSame('error', $this->systemLogEntries('app.fake.failed')[0]['level']);
        $this->assertSame('exception', $ctx['reason']);
        $this->assertSame('App\\Jobs\\Fake', $ctx['input']['job']);
        $this->assertSame(7, $ctx['input']['team_id']);
        $this->assertSame(RuntimeException::class, $ctx['error']['class']);
        $this->assertStringContainsString('boom', $ctx['error']['message']);
    }

    public function test_the_code_is_derived_from_the_domain_and_job_name(): void
    {
        $this->assertSame('ingestion.process_raw_event.failed', JobFailureReporter::codeFor('App\Domains\Ingestion\Jobs\ProcessRawEventJob'));
        $this->assertSame('ai.evaluate_event_media.failed', JobFailureReporter::codeFor('App\Domains\AI\Jobs\EvaluateEventMediaJob'));
        $this->assertSame('tenant_config.apply_defaults.failed', JobFailureReporter::codeFor('App\Domains\TenantConfig\Jobs\ApplyDefaultsJob'));
    }

    public function test_the_raw_message_never_reaches_the_log(): void
    {
        JobFailureReporter::report('App\Jobs\Fake', new RuntimeException('to +525512345678'));

        $this->assertSame('to [phone]', $this->assertSystemLogged('app.fake.failed')['error']['message']);
    }

    public function test_a_throwing_listener_never_makes_report_throw(): void
    {
        SystemLog::listen(static function (): void {
            throw new RuntimeException('listener down');
        });

        JobFailureReporter::report('App\Jobs\Fake', new RuntimeException('boom'));

        $this->addToAssertionCount(1);
    }
}
