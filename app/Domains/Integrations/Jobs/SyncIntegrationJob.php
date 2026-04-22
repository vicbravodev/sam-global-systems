<?php

namespace App\Domains\Integrations\Jobs;

use App\Domains\Integrations\Actions\SyncIntegration;
use App\Domains\Integrations\Models\IntegrationSyncJob;
use App\Domains\Integrations\Models\TenantIntegration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncIntegrationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 1800;

    public function __construct(
        public readonly TenantIntegration $integration,
        public readonly IntegrationSyncJob $syncJob,
    ) {
        $this->onQueue('sync');
    }

    public function handle(SyncIntegration $syncIntegration): void
    {
        $syncIntegration->execute($this->integration, $this->syncJob);
    }

    public function failed(\Throwable $exception): void
    {
        $this->syncJob->markAsFailed($exception->getMessage());
    }

    public function uniqueId(): string
    {
        return "sync-integration-{$this->integration->id}";
    }
}
