<?php

namespace Tests\Feature\Domains\Ingestion;

use App\Domains\Ingestion\Jobs\PruneDeduplicationKeysJob;
use App\Domains\Ingestion\Models\EventDeduplicationKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneDeduplicationKeysJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_expired_keys_are_removed(): void
    {
        $expired = EventDeduplicationKey::factory()->create(['expires_at' => now()->subHour()]);
        $alive = EventDeduplicationKey::factory()->create(['expires_at' => now()->addHour()]);

        (new PruneDeduplicationKeysJob)->handle();

        $this->assertDatabaseMissing('event_deduplication_keys', ['id' => $expired->id]);
        $this->assertDatabaseHas('event_deduplication_keys', ['id' => $alive->id]);
    }
}
