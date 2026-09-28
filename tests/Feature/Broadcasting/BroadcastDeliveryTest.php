<?php

namespace Tests\Feature\Broadcasting;

use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentStatus;
use App\Domains\Incidents\Support\IncidentCreatedBroadcast;
use App\Models\Team;
use App\Support\Broadcasting\QueuesRealtimeBroadcast;
use App\Support\NavBadgeCache;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Delivery guarantees every socket broadcast must keep (see
 * {@see QueuesRealtimeBroadcast}).
 */
class BroadcastDeliveryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<class-string>
     */
    private function broadcastClasses(): array
    {
        $classes = [];

        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            $class = 'App\\'.str_replace(
                ['/', '.php'],
                ['\\', ''],
                Str::after($file->getRealPath(), app_path().DIRECTORY_SEPARATOR),
            );

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if (! $reflection->isAbstract() && $reflection->implementsInterface(ShouldBroadcast::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    public function test_every_broadcast_is_rescued_and_queued_broadcasts_use_the_realtime_queue_after_commit(): void
    {
        $classes = $this->broadcastClasses();

        $this->assertNotEmpty($classes);

        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);

            $this->assertTrue(
                $reflection->implementsInterface(ShouldRescue::class),
                "{$class} must implement ShouldRescue: a socket failure must not fail the action that emitted it.",
            );

            if ($reflection->implementsInterface(ShouldBroadcastNow::class)) {
                continue;
            }

            $this->assertContains(
                QueuesRealtimeBroadcast::class,
                class_uses_recursive($class),
                "{$class} must use QueuesRealtimeBroadcast (broadcasts queue + after commit).",
            );
        }
    }

    public function test_the_broadcasts_queue_is_served_by_a_horizon_supervisor_in_every_environment(): void
    {
        $served = collect(config('horizon.defaults'))
            ->filter(fn (array $supervisor) => in_array('broadcasts', $supervisor['queue'] ?? [], true))
            ->keys();

        $this->assertCount(1, $served);

        foreach (config('horizon.environments') as $environment => $supervisors) {
            $this->assertArrayHasKey(
                $served->first(),
                $supervisors,
                "Horizon environment [{$environment}] must size the realtime supervisor.",
            );
        }
    }

    public function test_a_queued_broadcast_is_pushed_to_the_broadcasts_queue_flagged_after_commit(): void
    {
        Queue::fake();

        $event = new IncidentCreatedBroadcast(
            teamId: 1,
            incidentId: 1,
            title: 'Pánico',
            priority: 'critical',
            status: 'open',
            assetId: null,
            driverId: null,
            openedAt: now()->toIso8601String(),
        );

        broadcast($event);

        // The fake records immediately; the real queue honours the flag and
        // holds the push until the surrounding transaction commits.
        Queue::assertPushedOn(
            'broadcasts',
            BroadcastEvent::class,
            fn (BroadcastEvent $job) => $job->afterCommit === true,
        );
    }

    public function test_opening_or_moving_an_incident_forgets_its_team_nav_badge_cache_only(): void
    {
        $team = Team::factory()->create();
        $other = Team::factory()->create();

        Cache::put(NavBadgeCache::key($team->id), ['inbox' => 0], 60);
        Cache::put(NavBadgeCache::key($other->id), ['inbox' => 7], 60);

        $incident = Incident::factory()->create(['team_id' => $team->id]);

        $this->assertFalse(Cache::has(NavBadgeCache::key($team->id)));
        $this->assertSame(['inbox' => 7], Cache::get(NavBadgeCache::key($other->id)));

        Cache::put(NavBadgeCache::key($team->id), ['inbox' => 1], 60);

        $incident->update(['title' => 'Solo cambia el título']);
        $this->assertTrue(Cache::has(NavBadgeCache::key($team->id)), 'A change that does not move the counter keeps the cache.');

        $incident->update([
            'incident_status_id' => IncidentStatus::factory()->create(['is_terminal' => true])->id,
        ]);
        $this->assertFalse(Cache::has(NavBadgeCache::key($team->id)));
    }
}
