<?php

namespace Tests\Feature\Support;

use App\Providers\NightwatchServiceProvider;
use App\Support\NightwatchIngestBudget;
use Illuminate\Contracts\Cache\Repository;
use Laravel\Nightwatch\Events\IngestingEvents;
use Laravel\Nightwatch\Facades\Nightwatch;
use Mockery;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class NightwatchServiceProviderTest extends TestCase
{
    use AssertsSystemLog;

    protected function tearDown(): void
    {
        NightwatchIngestBudget::resetOutageFlag();
        NightwatchServiceProvider::resetUnrecoverableThrottle();

        parent::tearDown();
    }

    /**
     * @param  int  $count  eventos a ingerir (los registros `user` no cuentan)
     */
    private function batch(int $count): IngestingEvents
    {
        return new IngestingEvents(array_merge(
            array_fill(0, $count, ['t' => 'query']),
            [['t' => 'user']],
        ));
    }

    public function test_ingest_is_unlimited_without_a_cap(): void
    {
        config(['nightwatch.daily_event_cap' => null]);

        $this->assertNull((new NightwatchIngestBudget)($this->batch(10_000)));
        $this->assertSystemNotLogged('observability.nightwatch.ingest_capped');
    }

    public function test_ingest_stops_once_the_daily_cap_is_crossed_and_logs_it_once(): void
    {
        config(['nightwatch.daily_event_cap' => 100]);
        $budget = new NightwatchIngestBudget;

        $this->assertNull($budget($this->batch(60)));
        $this->assertNull($budget($this->batch(40)), 'Llegar justo al tope todavía se envía.');
        $this->assertFalse($budget($this->batch(1)));
        $this->assertFalse($budget($this->batch(5)));

        $context = $this->assertSystemLogged('observability.nightwatch.ingest_capped');
        $this->assertSame('daily_cap_reached', $context['reason']);
        $this->assertSame(100, $context['calc']['daily_event_cap']);
        $this->assertSame(101, $context['calc']['ingested_today']);
        $this->assertCount(1, $this->systemLogEntries('observability.nightwatch.ingest_capped'), 'Una línea por día, no una por lote.');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_cap_resets_the_next_day(): void
    {
        config(['nightwatch.daily_event_cap' => 10]);
        $budget = new NightwatchIngestBudget;

        $this->travelTo(now()->setTime(23, 59));
        $this->assertFalse($budget($this->batch(11)));

        $this->travelTo(now()->addDay()->setTime(0, 1));
        $this->assertNull($budget($this->batch(5)));
    }

    public function test_a_cache_outage_lets_events_through_and_is_logged_once(): void
    {
        config(['nightwatch.daily_event_cap' => 10]);
        $cache = Mockery::mock(Repository::class);
        $cache->shouldReceive('add', 'increment')->andThrow(new RuntimeException('valkey down'));
        $budget = new NightwatchIngestBudget($cache);

        $this->assertNull($budget($this->batch(50)));
        $this->assertNull($budget($this->batch(50)));

        $context = $this->assertSystemLogged('observability.nightwatch.budget_unavailable');
        $this->assertSame('cache_unavailable', $context['reason']);
        $this->assertCount(1, $this->systemLogEntries('observability.nightwatch.budget_unavailable'));
    }

    public function test_an_unrecoverable_nightwatch_failure_is_logged_outside_nightwatch(): void
    {
        NightwatchServiceProvider::reportUnrecoverable(new RuntimeException('agent unreachable at ana@cliente.mx'));

        $context = $this->assertSystemLogged('observability.nightwatch.unrecoverable', fn (array $context): bool => $context['reason'] === 'nightwatch_exception');
        $this->assertSame('RuntimeException', $context['error']['class']);
        $this->assertSame('json', $this->systemLogEntries('observability.nightwatch.unrecoverable')[0]['channel']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_provider_registers_every_redactor_and_the_user_resolver(): void
    {
        // Core es final: se sustituye el facade por un spy sin tipo.
        Nightwatch::swap($spy = Mockery::spy());

        (new NightwatchServiceProvider($this->app))->boot();

        foreach (['user', 'redactRequests', 'redactExceptions', 'redactOutgoingRequests', 'redactCommands', 'redactMail', 'redactCacheEvents'] as $method) {
            $spy->shouldHaveReceived($method)->once()->with(Mockery::type('callable'));
        }
    }
}
