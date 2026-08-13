<?php

namespace Tests\Feature\Support;

use App\Support\JobFailureReporter;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class JobFailureReporterTest extends TestCase
{
    public function test_reports_at_error_level_with_context(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return str_contains($message, 'Job failed')
                    && $context['job'] === 'App\\Jobs\\Fake'
                    && $context['team_id'] === 7
                    && $context['exception'] === 'RuntimeException'
                    && str_contains($context['message'], 'boom');
            });

        JobFailureReporter::report('App\Jobs\Fake', new RuntimeException('boom'), ['team_id' => 7]);
    }
}
