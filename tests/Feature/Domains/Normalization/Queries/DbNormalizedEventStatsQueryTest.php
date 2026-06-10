<?php

namespace Tests\Feature\Domains\Normalization\Queries;

use App\Contracts\Normalization\NormalizedEventStatsQuery;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Normalization\Queries\DbNormalizedEventStatsQuery;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DbNormalizedEventStatsQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_binding_resolves_to_db_implementation(): void
    {
        $this->assertInstanceOf(
            DbNormalizedEventStatsQuery::class,
            app(NormalizedEventStatsQuery::class),
        );
    }

    public function test_counts_events_per_provider_since_given_moment(): void
    {
        $team = Team::factory()->create();
        $providerA = IntegrationProvider::factory()->create();
        $providerB = IntegrationProvider::factory()->create();

        NormalizedEvent::factory()->count(2)->create([
            'team_id' => $team->id,
            'provider_id' => $providerA->id,
            'occurred_at' => now()->subHours(3),
        ]);
        NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'provider_id' => $providerB->id,
            'occurred_at' => now()->subHours(1),
        ]);

        // Outside the window: must not be counted.
        NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'provider_id' => $providerA->id,
            'occurred_at' => now()->subDays(2),
        ]);

        $counts = app(DbNormalizedEventStatsQuery::class)
            ->countByProviderSince($team->id, now()->subDay());

        $this->assertSame(2, $counts[$providerA->id]);
        $this->assertSame(1, $counts[$providerB->id]);
    }

    public function test_counts_are_isolated_by_tenant(): void
    {
        $team = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $provider = IntegrationProvider::factory()->create();

        NormalizedEvent::factory()->create([
            'team_id' => $otherTeam->id,
            'provider_id' => $provider->id,
            'occurred_at' => now()->subHour(),
        ]);

        $counts = app(DbNormalizedEventStatsQuery::class)
            ->countByProviderSince($team->id, now()->subDay());

        $this->assertSame([], $counts);
    }
}
