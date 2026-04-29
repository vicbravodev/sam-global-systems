<?php

namespace App\Domains\Analytics\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReportGenerated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $reportExecutionId,
        public readonly string $reportType,
        public readonly string $outputFormat,
    ) {}
}
