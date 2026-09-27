<?php

namespace Tests\Unit\Domains\AI;

use App\Domains\AI\Jobs\EvaluateEventJob;
use App\Domains\AI\Jobs\EvaluateEventMediaJob;
use App\Domains\AI\Jobs\ReevaluateEventJob;
use Tests\TestCase;

/**
 * Si `retry_after` de la conexión es menor que el `$timeout` de un job, el
 * worker considera el job perdido y lo reentrega mientras el primero sigue
 * corriendo: dos llamadas pagadas al proveedor de IA por el mismo evento.
 */
class AIJobsRetryAfterTest extends TestCase
{
    public function test_redis_retry_after_exceeds_the_longest_ai_job_timeout(): void
    {
        $timeouts = [
            (new EvaluateEventJob(1))->timeout,
            (new ReevaluateEventJob(1, 'manual'))->timeout,
            (new EvaluateEventMediaJob(1, [1]))->timeout,
        ];

        $retryAfter = (int) config('queue.connections.redis.retry_after');

        $this->assertGreaterThan(max($timeouts), $retryAfter);
    }
}
