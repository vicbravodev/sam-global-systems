<?php

namespace Tests\Feature\Domains\Copilot;

use App\Domains\Assets\Enums\TelemetryType;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetTelemetrySnapshot;
use App\Domains\Copilot\Actions\AnswerCopilotQuestion;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class AssetTimelineToolTest extends TestCase
{
    use AssertsSystemLog, CopilotFixtures, RefreshDatabase, RunsCopilotTools;

    private const PERMISSIONS = ['assets.view', 'incidents.view'];

    private CarbonImmutable $now;

    private Team $team;

    private Asset $truck;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);

        $this->now = CarbonImmutable::parse('2026-09-30 12:00');
        $this->travelTo($this->now);
        $this->team = Team::factory()->create();
        $this->truck = Asset::factory()->create(['team_id' => $this->team->id, 'code' => 'T555', 'name' => 'Kenworth', 'last_seen_at' => $this->now]);
    }

    private function engine(string $state, CarbonImmutable $at): void
    {
        AssetTelemetrySnapshot::factory()->create([
            'asset_id' => $this->truck->id,
            'telemetry_type' => TelemetryType::Ignition,
            'data_json' => ['value' => $state],
            'recorded_at' => $at,
        ]);
    }

    public function test_merges_events_incidents_and_long_idle_in_order(): void
    {
        $type = EventType::factory()->create(['code' => 'harsh_brake', 'name' => 'Frenado brusco']);
        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->team->id,
            'asset_id' => $this->truck->id,
            'event_type_id' => $type->id,
            'event_severity_id' => EventSeverity::factory()->high()->create()->id,
            'occurred_at' => $this->now->subDays(3),
        ]);
        $incident = Incident::factory()->create([
            'team_id' => $this->team->id,
            'asset_id' => $this->truck->id,
            'title' => 'Frenado brusco en curva',
            'opened_at' => $this->now->subDays(2),
        ]);
        // 40 min idle (shown) and 5 min idle (hidden).
        $this->engine('Idle', $this->now->subDay());
        $this->engine('On', $this->now->subDay()->addMinutes(40));
        $this->engine('Idle', $this->now->subHours(5));
        $this->engine('On', $this->now->subHours(5)->addMinutes(5));
        // Outside the window.
        NormalizedEvent::factory()->create(['team_id' => $this->team->id, 'asset_id' => $this->truck->id, 'occurred_at' => $this->now->subDays(30)]);

        $out = $this->callTool($this->team, self::PERMISSIONS, 'asset_timeline', ['asset_code' => 'T555']);

        $items = $out['facts']['items'];
        $this->assertSame(['event', 'incident', 'idle'], array_column($items, 'kind'));
        $this->assertSame('Frenado brusco', $items[0]['label']);
        $this->assertSame('high', $items[0]['severity']);
        $this->assertSame(40, $items[2]['minutes']);
        $this->assertArrayNotHasKey('href', $items[0]);

        $block = $this->toolBlock('timeline');
        $this->assertCount(3, $block['items']);
        $this->assertStringContainsString("/events/{$event->id}", $block['items'][0]['href']);
        $this->assertStringContainsString("/incidents/{$incident->id}", $block['items'][1]['href']);
        $this->assertArrayNotHasKey('href', $block['items'][2]);
        $this->assertSame($this->truck->id, $this->toolCollector->lastAssetId());
        $this->assertSystemLogged('copilot.tool.ran', fn ($c) => $c['input']['tool'] === 'asset_timeline' && $c['input']['asset_id'] === $this->truck->id);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_keeps_the_most_recent_forty_items(): void
    {
        NormalizedEvent::factory()->count(45)->sequence(fn ($s) => ['occurred_at' => $this->now->subMinutes(100 - $s->index)])
            ->create(['team_id' => $this->team->id, 'asset_id' => $this->truck->id]);

        $items = $this->callTool($this->team, self::PERMISSIONS, 'asset_timeline', ['asset_code' => 'T555'])['facts']['items'];

        $this->assertCount(40, $items);
        $this->assertSame($this->now->subMinutes(56)->toIso8601String(), CarbonImmutable::parse($items[39]['at'])->toIso8601String());
        $this->assertTrue(CarbonImmutable::parse($items[0]['at'])->lt(CarbonImmutable::parse($items[39]['at'])));
    }

    public function test_ui_block_is_capped_at_twelve_but_keeps_incidents_and_the_full_total(): void
    {
        NormalizedEvent::factory()->count(30)->sequence(fn ($s) => ['occurred_at' => $this->now->subMinutes(200 - $s->index)])
            ->create(['team_id' => $this->team->id, 'asset_id' => $this->truck->id]);
        // Oldest item of all: must survive the cap because incidents are preferred.
        $incident = Incident::factory()->create([
            'team_id' => $this->team->id,
            'asset_id' => $this->truck->id,
            'opened_at' => $this->now->subDays(2),
        ]);

        $out = $this->callTool($this->team, self::PERMISSIONS, 'asset_timeline', ['asset_code' => 'T555']);

        $block = $this->toolBlock('timeline');
        $this->assertCount(12, $block['items']);
        $this->assertSame(31, $block['total']);
        $this->assertStringContainsString("/assets/{$this->truck->id}", $block['href']);
        $this->assertContains('incident', array_column($block['items'], 'kind'));
        $this->assertStringContainsString("/incidents/{$incident->id}", collect($block['items'])->firstWhere('kind', 'incident')['href']);
        $ats = array_map(fn ($i) => CarbonImmutable::parse($i['at'])->getTimestamp(), $block['items']);
        $sorted = $ats;
        sort($sorted);
        $this->assertSame($sorted, $ats);
        $this->assertCount(31, $out['facts']['items']);
    }

    public function test_empty_timeline_returns_a_notice(): void
    {
        $out = $this->callTool($this->team, self::PERMISSIONS, 'asset_timeline', ['asset_code' => 'T555']);

        $this->assertSame([], $out['facts']['items']);
        $this->assertSame('notice', $this->toolCollector->blocks()[0]['type']);
    }

    public function test_requires_asset_code(): void
    {
        $this->assertSame('argumentos inválidos', $this->callTool($this->team, self::PERMISSIONS, 'asset_timeline')['error']);
    }

    public function test_another_tenants_unit_is_not_found(): void
    {
        $other = Team::factory()->create();
        $foreign = Asset::factory()->create(['team_id' => $other->id, 'code' => 'T777']);
        NormalizedEvent::factory()->create(['team_id' => $other->id, 'asset_id' => $foreign->id, 'occurred_at' => $this->now->subDay()]);

        $out = $this->callTool($this->team, self::PERMISSIONS, 'asset_timeline', ['asset_code' => 'T777']);

        $this->assertSame('unidad no encontrada', $out['error']);
    }

    public function test_new_intents_do_not_break_the_deterministic_path(): void
    {
        foreach (['asset_ranking', 'event_search', 'asset_timeline'] as $intent) {
            $answer = app(AnswerCopilotQuestion::class)->execute(
                $this->team->id, (string) $this->team->slug, self::PERMISSIONS, false, 'dame la línea de tiempo de la T555', ['intent' => $intent],
            );

            $this->assertSame($intent, $answer->intent->value);
        }
    }
}
