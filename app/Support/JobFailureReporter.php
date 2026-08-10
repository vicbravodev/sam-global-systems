<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

final class JobFailureReporter
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function report(string $jobClass, Throwable $e, array $context = []): void
    {
        Log::error('Job failed: '.$jobClass, array_merge([
            'job' => $jobClass,
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile().':'.$e->getLine(),
        ], $context));
    }
}
