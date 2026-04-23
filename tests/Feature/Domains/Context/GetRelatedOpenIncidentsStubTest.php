<?php

namespace Tests\Feature\Domains\Context;

use App\Domains\Context\Actions\GetRelatedOpenIncidents;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SPEC-11-DEFERRED: verifies that the stub returns an empty collection
 * until the Incidents domain is implemented in spec 11.
 */
class GetRelatedOpenIncidentsStubTest extends TestCase
{
    use RefreshDatabase;

    public function test_stub_returns_empty_collection(): void
    {
        $user = User::factory()->create();
        $event = NormalizedEvent::factory()->create(['team_id' => $user->currentTeam->id]);

        $result = app(GetRelatedOpenIncidents::class)->execute($event);

        $this->assertTrue($result->isEmpty());
    }
}
