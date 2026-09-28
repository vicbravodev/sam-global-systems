<?php

namespace Tests\Feature\Architecture;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Guards the production queue topology: a job whose timeout outlives its
 * connection's retry_after is re-delivered to a second worker mid-run, and a
 * queue no supervisor consumes is never processed.
 */
class QueueTopologyTest extends TestCase
{
    public function test_queued_broadcasts_go_to_a_queue_production_consumes(): void
    {
        $consumed = $this->productionQueues();

        foreach ($this->appClasses() as $class) {
            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract()
                || ! $reflection->implementsInterface(ShouldBroadcast::class)
                || $reflection->implementsInterface(ShouldBroadcastNow::class)) {
                continue;
            }

            $event = $reflection->newInstanceWithoutConstructor();
            $queue = method_exists($event, 'broadcastQueue')
                ? $event->broadcastQueue()
                : ($event->broadcastQueue ?? null);

            $this->assertNotNull($queue, "{$class} is queued but declares no broadcast queue: it would land on `default`.");

            $this->assertContains($queue, $consumed, "{$class} broadcasts on `{$queue}`, which no production supervisor consumes.");
        }
    }

    public function test_every_supervisor_timeout_fits_its_connection_retry_after(): void
    {
        foreach (config('horizon.defaults') as $name => $supervisor) {
            $retryAfter = (int) config("queue.connections.{$supervisor['connection']}.retry_after");

            $this->assertLessThan(
                $retryAfter,
                (int) $supervisor['timeout'],
                "{$name}: timeout must stay below the connection's retry_after ({$retryAfter}).",
            );
        }
    }

    public function test_job_timeouts_fit_the_retry_after_of_the_supervisor_consuming_their_queue(): void
    {
        $retryAfterByQueue = [];

        foreach (config('horizon.defaults') as $supervisor) {
            foreach ($supervisor['queue'] as $queue) {
                $retryAfterByQueue[$queue] = (int) config("queue.connections.{$supervisor['connection']}.retry_after");
            }
        }

        foreach ($this->appClasses() as $class) {
            $reflection = new ReflectionClass($class);

            if (! Str::endsWith($class, 'Job') || $reflection->isAbstract() || ! $reflection->hasProperty('timeout')) {
                continue;
            }

            $timeout = $reflection->getProperty('timeout')->getDefaultValue();
            $source = (string) file_get_contents((string) $reflection->getFileName());

            if (! is_int($timeout) || ! preg_match("/onQueue\\('([a-z-]+)'\\)/", $source, $match)) {
                continue;
            }

            $queue = $match[1];

            $this->assertArrayHasKey($queue, $retryAfterByQueue, "{$class} uses queue `{$queue}`, which no supervisor consumes.");
            $this->assertLessThan(
                $retryAfterByQueue[$queue],
                $timeout,
                "{$class} (timeout {$timeout}s) outlives retry_after on `{$queue}` and would run twice.",
            );
        }
    }

    /**
     * @return list<string>
     */
    private function productionQueues(): array
    {
        $queues = [];

        foreach (config('horizon.defaults') as $name => $supervisor) {
            if (array_key_exists($name, (array) config('horizon.environments.production'))) {
                array_push($queues, ...$supervisor['queue']);
            }
        }

        return $queues;
    }

    /**
     * @return list<class-string>
     */
    private function appClasses(): array
    {
        $classes = [];

        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
