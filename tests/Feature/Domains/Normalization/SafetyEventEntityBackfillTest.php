<?php

namespace Tests\Feature\Domains\Normalization;

use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * RefreshDatabase ya corrió la migración sobre una tabla vacía: el test baja
 * las columnas, siembra filas como estaban antes y vuelve a correr `up()`.
 */
class SafetyEventEntityBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_09_110000_add_provider_state_to_normalized_events.php');
    }

    private function safetyRow(Team $team, string $eventId, string $state, string $updatedAt): NormalizedEvent
    {
        $raw = RawEvent::factory()->processed()->create([
            'team_id' => $team->id,
            'external_event_id' => $eventId,
            'deduplication_key' => 'safety:'.$eventId.':'.$state,
            'payload_json' => ['id' => $eventId, 'eventState' => $state, 'updatedAtTime' => $updatedAt],
        ]);

        return NormalizedEvent::factory()->create(['team_id' => $team->id, 'raw_event_id' => $raw->id]);
    }

    public function test_backfill_keeps_the_newest_row_of_each_event_and_supersedes_the_rest(): void
    {
        $migration = $this->migration();
        $team = Team::factory()->create();
        $other = Team::factory()->create();

        $migration->down();

        $old = $this->safetyRow($team, 'evt-1', 'needsReview', '2026-10-04T10:00:00Z');
        $newest = $this->safetyRow($team, 'evt-1', 'dismissed', '2026-10-04T12:00:00Z');
        // Mismo id de proveedor en otro tenant: otra entidad.
        $foreign = $this->safetyRow($other, 'evt-1', 'needsReview', '2026-10-04T10:00:00Z');
        $panic = NormalizedEvent::factory()->create(['team_id' => $team->id]);

        $migration->up();

        $this->assertTrue(Schema::hasColumn('normalized_events', 'provider_event_key'));

        $newest = $newest->fresh();
        $this->assertSame('safety:evt-1', $newest?->provider_event_key);
        $this->assertSame('dismissed', $newest->provider_state);
        $this->assertSame('2026-10-04 12:00:00', $newest->provider_dismissed_at?->utc()->format('Y-m-d H:i:s'));

        $old = $old->fresh();
        $this->assertNull($old?->provider_event_key);
        $this->assertSame(NormalizedEvent::PROVIDER_STATE_SUPERSEDED, $old->provider_state);

        $this->assertSame('safety:evt-1', $foreign->fresh()?->provider_event_key);
        $this->assertSame('needsReview', $foreign->fresh()?->provider_state);

        $this->assertNull($panic->fresh()?->provider_event_key);
        $this->assertNull($panic->fresh()?->provider_state);

        $this->assertSame(2, DB::table('normalized_events')->whereNotNull('provider_event_key')->count());
    }
}
