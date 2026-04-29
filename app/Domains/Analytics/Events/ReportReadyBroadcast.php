<?php

namespace App\Domains\Analytics\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class ReportReadyBroadcast implements ShouldBroadcast
{
    use SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $reportExecutionId,
        public readonly string $reportName,
        public readonly string $outputFormat,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("accounts.{$this->teamId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'report.ready';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'report_execution_id' => $this->reportExecutionId,
            'report_name' => $this->reportName,
            'output_format' => $this->outputFormat,
        ];
    }
}
