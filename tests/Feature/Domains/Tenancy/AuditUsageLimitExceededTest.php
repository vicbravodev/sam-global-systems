<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\Tenancy\Events\UsageLimitExceeded;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditUsageLimitExceededTest extends TestCase
{
    use RefreshDatabase;

    public function test_exceeding_a_limit_is_written_to_the_billing_audit(): void
    {
        $team = Team::factory()->create();

        UsageLimitExceeded::dispatch($team->id, 'monitored_assets', 101, 100);

        $this->assertDatabaseHas('audit_logs', [
            'team_id' => $team->id,
            'action' => 'usage.limit_exceeded',
            'category' => 'billing',
        ]);
    }
}
