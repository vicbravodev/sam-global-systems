<?php

namespace Tests\Feature\Support;

use App\Providers\TelescopeServiceProvider;
use App\Support\PipelineTrace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Schema;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeServiceProvider as TelescopePackageProvider;
use Tests\TestCase;

/**
 * Telescope guarda datos de TODOS los tenants: sólo existe en local.
 */
class TelescopeLocalOnlyTest extends TestCase
{
    protected function tearDown(): void
    {
        Telescope::auth(static fn (): bool => false);
        Context::flush();

        parent::tearDown();
    }

    public function test_telescope_is_not_loaded_outside_local(): void
    {
        $this->assertFalse($this->app->providerIsLoaded(TelescopePackageProvider::class));
        $this->assertFalse($this->app->providerIsLoaded(TelescopeServiceProvider::class));
        $this->assertFalse(Schema::hasTable('telescope_entries'));

        $this->get('/telescope')->assertNotFound();
    }

    public function test_the_dashboard_only_opens_in_local(): void
    {
        $provider = new TelescopeServiceProvider($this->app);
        $provider->boot();

        $this->assertFalse(Telescope::check(Request::create('/telescope')), 'Fuera de local nadie entra, ni un usuario autenticado.');

        $this->app['env'] = 'local';
        $this->assertTrue(Telescope::check(Request::create('/telescope')));
    }

    public function test_entries_are_tagged_with_the_trace_and_team_from_context(): void
    {
        $this->assertSame([], TelescopeServiceProvider::traceTags());

        $traceId = PipelineTrace::begin(7, 'samsara');

        $this->assertSame(["trace:{$traceId}", 'team:7'], TelescopeServiceProvider::traceTags());
    }

    public function test_high_frequency_and_tooling_noise_is_filtered_but_failures_are_kept(): void
    {
        $job = fn (string $name, string $queue, string $status = 'pending'): IncomingEntry => IncomingEntry::make(['name' => $name, 'queue' => $queue, 'status' => $status])->type(EntryType::JOB);

        $this->assertTrue(TelescopeServiceProvider::shouldRecord($job('App\\Domains\\Ingestion\\Jobs\\ProcessRawEventJob', 'ingestion')));
        $this->assertFalse(TelescopeServiceProvider::shouldRecord($job('App\\Domains\\Assets\\Jobs\\FollowVehicleStatsFeedJob', 'telematics')));
        $this->assertTrue(TelescopeServiceProvider::shouldRecord($job('App\\Domains\\Assets\\Jobs\\FollowVehicleStatsFeedJob', 'telematics', 'failed')));
        $this->assertFalse(TelescopeServiceProvider::shouldRecord($job('Laravel\\Horizon\\Jobs\\MonitorTag', 'default')));
        $this->assertFalse(TelescopeServiceProvider::shouldRecord($job('Laravel\\Telescope\\Jobs\\ProcessPendingUpdates', 'default')));

        $task = fn (string $description): IncomingEntry => IncomingEntry::make(['command' => 'Closure', 'description' => $description])->type(EntryType::SCHEDULED_TASK);

        $this->assertFalse(TelescopeServiceProvider::shouldRecord($task('telematics:dispatch-feeds')));
        $this->assertTrue(TelescopeServiceProvider::shouldRecord($task('')));
    }
}
