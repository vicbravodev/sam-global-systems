<?php

namespace Tests\Feature\Support;

use App\Support\ObjectStorageFailure;
use App\Support\SystemLog;
use League\Flysystem\UnableToWriteFile;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class ObjectStorageFailureTest extends TestCase
{
    use AssertsSystemLog;

    public function test_reports_the_operation_and_context_at_error_level(): void
    {
        ObjectStorageFailure::report('some_upload', UnableToWriteFile::atLocation('x.pdf', 'refused'), ['team_id' => 3]);

        $ctx = $this->assertSystemLogged('storage.object.operation_failed');

        $this->assertSame('error', $this->systemLogEntries('storage.object.operation_failed')[0]['level']);
        $this->assertSame('storage_unavailable', $ctx['reason']);
        $this->assertSame('some_upload', $ctx['input']['operation']);
        $this->assertSame(3, $ctx['input']['team_id']);
        $this->assertSame(UnableToWriteFile::class, $ctx['error']['class']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_throwing_listener_never_makes_report_throw(): void
    {
        SystemLog::listen(static function (): void {
            throw new RuntimeException('listener down');
        });

        ObjectStorageFailure::report('some_upload', new RuntimeException('boom'));

        $this->addToAssertionCount(1);
    }
}
