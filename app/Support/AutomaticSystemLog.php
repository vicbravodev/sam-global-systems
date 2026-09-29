<?php

namespace App\Support;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Event;

/**
 * Red de fondo del log narrativo: lo que se registra sin que cada módulo lo
 * pida. Ciclo de vida de cada job, cada llamada HTTP saliente (sin query,
 * headers ni body) y los eventos de autenticación (sin el email).
 */
final class AutomaticSystemLog
{
    /**
     * Sufijo de host → proveedor, para filtrar las llamadas por proveedor.
     *
     * @var array<string, string>
     */
    private const array PROVIDERS = [
        'samsara.com' => 'samsara',
        'twilio.com' => 'twilio',
        'openai.com' => 'openai',
        'anthropic.com' => 'anthropic',
        'slack.com' => 'slack',
        'amazonaws.com' => 's3',
    ];

    /**
     * @var array<int, int> spl_object_id(job) → hrtime de inicio
     */
    private static array $startedAt = [];

    public static function register(): void
    {
        Event::listen(JobProcessing::class, static function (JobProcessing $event): void {
            self::$startedAt[spl_object_id($event->job)] = hrtime(true);
        });

        Event::listen(JobProcessed::class, static function (JobProcessed $event): void {
            $input = self::jobInput($event->job);
            $duration = self::jobDuration($event->job);

            if ($event->job->isReleased()) {
                SystemLog::skipped('queue.job.released', reason: 'released', input: $input, debug: self::isHotQueue($event->job));

                return;
            }

            SystemLog::ok('queue.job.finished', input: $input, durationMs: $duration, debug: self::isHotQueue($event->job));
        });

        Event::listen(JobExceptionOccurred::class, static function (JobExceptionOccurred $event): void {
            SystemLog::degraded('queue.job.attempt_failed', reason: 'exception', input: self::jobInput($event->job) + [
                'max_tries' => $event->job->maxTries(),
            ], error: $event->exception);
        });

        Event::listen(JobFailed::class, static function (JobFailed $event): void {
            $reason = match (true) {
                $event->exception instanceof MaxAttemptsExceededException => 'max_attempts_exceeded',
                $event->exception instanceof TimeoutExceededException => 'timeout',
                default => 'exception',
            };

            SystemLog::failed('queue.job.failed', reason: $reason, input: self::jobInput($event->job), error: $event->exception);
            unset(self::$startedAt[spl_object_id($event->job)]);
        });

        Event::listen(ResponseReceived::class, static function (ResponseReceived $event): void {
            $input = self::httpInput($event->request->url(), $event->request->method()) + ['status' => $event->response->status()];
            $seconds = $event->response->handlerStats()['total_time'] ?? null;
            $duration = is_numeric($seconds) ? (int) round(((float) $seconds) * 1000) : null;

            if ($event->response->successful() || $event->response->redirect()) {
                SystemLog::ok('http.client.request.completed', input: $input, durationMs: $duration);

                return;
            }

            SystemLog::degraded('http.client.request.completed', reason: 'http_error', input: $input, durationMs: $duration);
        });

        Event::listen(ConnectionFailed::class, static function (ConnectionFailed $event): void {
            SystemLog::failed('http.client.request.failed', reason: 'connection_failed', input: self::httpInput($event->request->url(), $event->request->method()), error: $event->exception);
        });

        Event::listen(Login::class, static fn (Login $event) => SystemLog::ok('auth.login.succeeded', input: ['user_id' => $event->user->getAuthIdentifier(), 'guard' => $event->guard, 'remember' => $event->remember]));
        Event::listen(Logout::class, static fn (Logout $event) => SystemLog::ok('auth.logout.succeeded', input: ['user_id' => $event->user?->getAuthIdentifier(), 'guard' => $event->guard]));
        Event::listen(PasswordReset::class, static fn (PasswordReset $event) => SystemLog::ok('auth.password.reset', input: ['user_id' => $event->user->getAuthIdentifier()]));

        Event::listen(Failed::class, static fn (Failed $event) => SystemLog::skipped('auth.login.failed', reason: 'invalid_credentials', input: [
            'user_id' => $event->user?->getAuthIdentifier(),
            'guard' => $event->guard,
            'login_fingerprint' => self::fingerprint($event->credentials['email'] ?? null),
        ]));

        Event::listen(Lockout::class, static fn (Lockout $event) => SystemLog::degraded('auth.login.locked_out', reason: 'too_many_attempts', input: [
            'route_name' => $event->request->route()?->getName(),
            'login_fingerprint' => self::fingerprint($event->request->input('email')),
        ]));
    }

    /**
     * @return array{job: string, queue: ?string, connection: ?string, attempt: int}
     */
    private static function jobInput(Job $job): array
    {
        return [
            'job' => $job->resolveName(),
            'queue' => $job->getQueue(),
            'connection' => $job->getConnectionName(),
            'attempt' => $job->attempts(),
        ];
    }

    private static function jobDuration(Job $job): ?int
    {
        $started = self::$startedAt[spl_object_id($job)] ?? null;
        unset(self::$startedAt[spl_object_id($job)]);

        return $started === null ? null : SystemLog::elapsedMs($started);
    }

    private static function isHotQueue(Job $job): bool
    {
        return $job->getQueue() === 'telematics';
    }

    /**
     * @return array{provider: string, method: string, host: string, path: string}
     */
    private static function httpInput(string $url, string $method): array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $provider = 'other';

        foreach (self::PROVIDERS as $suffix => $name) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                $provider = $name;

                break;
            }
        }

        return [
            'provider' => $provider,
            'method' => strtoupper($method),
            'host' => $host,
            'path' => (string) (parse_url($url, PHP_URL_PATH) ?: '/'),
        ];
    }

    private static function fingerprint(mixed $identifier): ?string
    {
        return is_string($identifier) && $identifier !== ''
            ? substr(hash('sha256', strtolower(trim($identifier))), 0, 12)
            : null;
    }
}
