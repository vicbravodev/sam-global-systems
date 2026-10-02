<?php

namespace Tests\Feature\Support;

use App\Domains\Integrations\Enums\SyncStatus;
use App\Domains\Integrations\Models\IntegrationSyncJob;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Support\ChunkedPurge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChunkedPurgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_every_matching_row_in_batches_and_nothing_else(): void
    {
        $sync = IntegrationSyncJob::factory()->for(TenantIntegration::factory(), 'tenantIntegration');
        $sync->completed()->count(5)->create();
        $kept = $sync->running()->count(2)->create();

        $outcome = ChunkedPurge::run(
            IntegrationSyncJob::query()->where('status', SyncStatus::Completed),
            chunk: 2,
        );

        // 5 filas en lotes de 2: 2 + 2 + 1, y una vuelta vacía que corta.
        $this->assertSame(['removed' => 5, 'batches' => 3], $outcome);
        $this->assertEqualsCanonicalizing($kept->modelKeys(), IntegrationSyncJob::query()->pluck('id')->all());
    }

    public function test_nothing_to_delete_is_zero_batches(): void
    {
        $this->assertSame(
            ['removed' => 0, 'batches' => 0],
            ChunkedPurge::run(IntegrationSyncJob::query(), chunk: 100),
        );
    }
}
