<?php

namespace Tests\Feature\Domains\Copilot;

use App\Domains\Assets\Models\Asset;
use App\Domains\Copilot\Data\CopilotTurnScope;
use App\Domains\Copilot\Support\CopilotToolbox;
use App\Domains\Copilot\Support\CopilotTurnCollector;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class SearchEventsToolTest extends TestCase
{
    use AssertsSystemLog, CopilotFixtures, RefreshDatabase, RunsCopilotTools;

    private CarbonImmutable $now;

    private Team $team;

    private EventType $brake;

    private EventType $speeding;

    private EventSeverity $high;

    private EventSeverity $low;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);

        $this->now = CarbonImmutable::parse('2026-09-30 12:00');
        $this->travelTo($this->now);
        $this->team = Team::factory()->create();
        $this->high = EventSeverity::factory()->high()->create();
        $this->low = EventSeverity::factory()->low()->create();
        $this->brake = EventType::factory()->create(['code' => 'harsh_brake', 'name' => 'Frenado brusco']);
        $this->speeding = EventType::factory()->create(['code' => 'speeding', 'name' => 'Exceso de velocidad']);
    }

    private function event(EventType $type, EventSeverity $severity, CarbonImmutable $at, ?Asset $asset = null, int $count = 1): void
    {
        NormalizedEvent::factory()->count($count)->create([
            'team_id' => $this->team->id,
            'asset_id' => $asset?->id,
            'event_type_id' => $type->id,
            'event_severity_id' => $severity->id,
            'occurred_at' => $at,
        ]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function search(array $args = [], array $permissions = ['assets.view', 'incidents.view']): array
    {
        return $this->callTool($this->team, $permissions, 'search_events', $args);
    }

    public function test_counts_events_in_the_window_by_type_and_severity(): void
    {
        $truck = Asset::factory()->create(['team_id' => $this->team->id, 'code' => 'T555']);
        $this->event($this->brake, $this->high, $this->now->subDays(1), $truck, 3);
        $this->event($this->speeding, $this->low, $this->now->subDays(2), $truck, 2);
        $this->event($this->brake, $this->high, $this->now->subDays(20), $truck, 4);

        $out = $this->search();

        $this->assertSame(5, $out['facts']['total']);
        $this->assertSame(['harsh_brake' => 3, 'speeding' => 2], $out['facts']['by_type']);
        $this->assertSame(['high' => 3, 'low' => 2], $out['facts']['by_severity']);
        $this->assertCount(5, $out['facts']['recent']);
        $this->assertSame(['at', 'type', 'severity', 'asset'], array_keys($out['facts']['recent'][0]));
        $this->assertSame('T555', $out['facts']['recent'][0]['asset']);

        $bars = $this->toolBlock('bars');
        $this->assertSame(5, $bars['total']);
        $this->assertSame([['label' => 'Frenado brusco', 'value' => 3], ['label' => 'Exceso de velocidad', 'value' => 2]], $bars['items']);

        $events = $this->toolBlock('events');
        $this->assertCount(5, $events['items']);
        $this->assertSame('high', $events['items'][0]['severity']);
        $this->assertStringContainsString('/events/', $events['items'][0]['href']);
        $this->assertSame('event', $this->toolCollector->sources()[0]['kind']);
        $this->assertSystemLogged('copilot.tool.ran', fn ($c) => $c['input']['tool'] === 'search_events');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_filters_by_type_severity_and_unit(): void
    {
        $a = Asset::factory()->create(['team_id' => $this->team->id, 'code' => 'T555']);
        $b = Asset::factory()->create(['team_id' => $this->team->id, 'code' => 'T600']);
        $this->event($this->brake, $this->high, $this->now->subDay(), $a, 2);
        $this->event($this->brake, $this->low, $this->now->subDay(), $b, 1);
        $this->event($this->speeding, $this->high, $this->now->subDay(), $b, 3);

        $this->assertSame(3, $this->search(['event_type' => 'harsh_brake'])['facts']['total']);
        $this->assertSame(5, $this->search(['severity' => 'high'])['facts']['total']);
        $this->assertSame(4, $this->search(['asset_code' => 'T600'])['facts']['total']);
        $this->assertSame(1, $this->search(['asset_code' => 'T600', 'event_type' => 'harsh_brake'])['facts']['total']);
    }

    public function test_explicit_window_is_respected(): void
    {
        $this->event($this->brake, $this->high, $this->now->subDays(20));
        $this->event($this->brake, $this->high, $this->now->subDays(1));

        $out = $this->search(['from' => $this->now->subDays(30)->toIso8601String(), 'to' => $this->now->subDays(10)->toIso8601String()]);

        $this->assertSame(1, $out['facts']['total']);
    }

    public function test_recent_list_is_capped_by_limit(): void
    {
        $this->event($this->brake, $this->high, $this->now->subHour(), count: 30);

        $default = $this->search();
        $this->assertSame(30, $default['facts']['total']);
        $this->assertCount(10, $default['facts']['recent']);

        $this->assertCount(25, $this->search(['limit' => 25])['facts']['recent']);
        $this->assertSame('argumentos inválidos', $this->search(['limit' => 26])['error']);
    }

    public function test_unknown_unit_is_an_error(): void
    {
        $out = $this->search(['asset_code' => 'ZZ999']);

        $this->assertSame('unidad no encontrada', $out['error']);
    }

    public function test_no_events_returns_a_notice(): void
    {
        $out = $this->search(['event_type' => 'harsh_brake']);

        $this->assertSame(0, $out['facts']['total']);
        $this->assertSame('notice', $this->toolCollector->blocks()[0]['type']);
    }

    public function test_requires_incidents_permission(): void
    {
        $names = array_map(fn ($t) => $t->name(), app(CopilotToolbox::class)
            ->for(CopilotTurnScope::fromTeam($this->team, ['assets.view'], false), new CopilotTurnCollector));

        $this->assertNotContains('search_events', $names);
        $this->assertNotContains('asset_timeline', $names);
        $this->assertContains('rank_assets', $names);
        $this->assertContains('find_assets', $names);
    }
}
