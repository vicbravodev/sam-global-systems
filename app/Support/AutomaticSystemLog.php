<?php

namespace App\Support;

use Closure;
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
    public const array PROVIDERS = [
        'samsara.com' => 'samsara',
        'twilio.com' => 'twilio',
        'openai.com' => 'openai',
        'anthropic.com' => 'anthropic',
        'slack.com' => 'slack',
        'amazonaws.com' => 's3',
    ];

    /**
     * Proveedores cuyo path es un endpoint de API, nunca una credencial. En el
     * resto (webhooks de tenant: Slack, Discord, Zapier, propios) el path puede
     * SER el secreto: se registra sólo su hash.
     *
     * @var list<string>
     */
    public const array PATH_ALLOWED_PROVIDERS = ['samsara', 'twilio', 'openai', 'anthropic', 's3'];

    /**
     * @var array<int, int> spl_object_id(job) → hrtime de inicio
     */
    private static array $startedAt = [];

    /**
     * Dentro de un job de la cola `telematics` (feed cada 5 s): sus llamadas
     * HTTP van al canal de telemática, las `ok` a debug.
     */
    private static bool $inTelematicsJob = false;

    public static function register(): void
    {
        self::listen(JobProcessing::class, static function (JobProcessing $event): void {
            self::$startedAt[spl_object_id($event->job)] = hrtime(true);
            self::$inTelematicsJob = self::isHotQueue($event->job);
        });

        self::listen(JobProcessed::class, static function (JobProcessed $event): void {
            self::$inTelematicsJob = false;
            $input = self::jobInput($event->job);
            $duration = self::jobDuration($event->job);
            $hot = self::isHotQueue($event->job);

            if ($event->job->isReleased()) {
                SystemLog::skipped('queue.job.released', reason: 'released', input: $input, debug: $hot, channel: self::jobChannel($event->job));

                return;
            }

            SystemLog::ok('queue.job.finished', input: $input, durationMs: $duration, debug: $hot, channel: self::jobChannel($event->job));
        });

        self::listen(JobExceptionOccurred::class, static function (JobExceptionOccurred $event): void {
            self::$inTelematicsJob = false;

            // jobDuration libera el inicio: un intento que se reintenta no deja rastro.
            SystemLog::degraded('queue.job.attempt_failed', reason: 'exception', input: self::jobInput($event->job) + [
                'max_tries' => $event->job->maxTries(),
            ], error: $event->exception, durationMs: self::jobDuration($event->job), channel: self::jobChannel($event->job));
        });

        self::listen(JobFailed::class, static function (JobFailed $event): void {
            self::$inTelematicsJob = false;
            $reason = match (true) {
                // TimeoutExceededException hereda de MaxAttemptsExceededException:
                // va primero o un timeout se reportaría como max_attempts_exceeded.
                $event->exception instanceof TimeoutExceededException => 'timeout',
                $event->exception instanceof MaxAttemptsExceededException => 'max_attempts_exceeded',
                default => 'exception',
            };

            unset(self::$startedAt[spl_object_id($event->job)]);

            SystemLog::failed('queue.job.failed', reason: $reason, input: self::jobInput($event->job), error: $event->exception, channel: self::jobChannel($event->job));
        });

        self::listen(ResponseReceived::class, static function (ResponseReceived $event): void {
            $input = self::httpInput($event->request->url(), $event->request->method()) + ['status' => $event->response->status()];
            $seconds = $event->response->handlerStats()['total_time'] ?? null;
            $duration = is_numeric($seconds) ? (int) round(((float) $seconds) * 1000) : null;

            if ($event->response->successful() || $event->response->redirect()) {
                SystemLog::ok('http.client.request.completed', input: $input, durationMs: $duration, debug: self::$inTelematicsJob, channel: self::httpChannel());

                return;
            }

            SystemLog::degraded('http.client.request.completed', reason: 'http_error', input: $input, durationMs: $duration, channel: self::httpChannel());
        });

        self::listen(ConnectionFailed::class, static function (ConnectionFailed $event): void {
            SystemLog::failed('http.client.request.failed', reason: 'connection_failed', input: self::httpInput($event->request->url(), $event->request->method()), error: $event->exception, channel: self::httpChannel());
        });

        self::listen(Login::class, static fn (Login $event) => SystemLog::ok('auth.login.succeeded', input: ['user_id' => $event->user->getAuthIdentifier(), 'guard' => $event->guard, 'remember' => $event->remember]));
        self::listen(Logout::class, static fn (Logout $event) => SystemLog::ok('auth.logout.succeeded', input: ['user_id' => $event->user?->getAuthIdentifier(), 'guard' => $event->guard]));
        self::listen(PasswordReset::class, static fn (PasswordReset $event) => SystemLog::ok('auth.password.reset', input: ['user_id' => $event->user->getAuthIdentifier()]));

        self::listen(Failed::class, static fn (Failed $event) => SystemLog::skipped('auth.login.failed', reason: 'invalid_credentials', input: [
            'user_id' => $event->user?->getAuthIdentifier(),
            'guard' => $event->guard,
            'login_fingerprint' => self::fingerprint($event->credentials['email'] ?? null),
        ]));

        self::listen(Lockout::class, static fn (Lockout $event) => SystemLog::degraded('auth.login.locked_out', reason: 'too_many_attempts', input: [
            'route_name' => $event->request->route()?->getName(),
            'login_fingerprint' => self::fingerprint($event->request->input('email')),
        ]));
    }

    /**
     * El guard de "nunca romper" vive dentro de SystemLog::write().
     */
    private static function listen(string $event, Closure $handler): void
    {
        Event::listen($event, $handler);
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

    private static function jobChannel(Job $job): ?string
    {
        return self::isHotQueue($job) ? 'telematics' : null;
    }

    private static function httpChannel(): ?string
    {
        return self::$inTelematicsJob ? 'telematics' : null;
    }

    /**
     * ¿El host es de un proveedor cuyo path es un endpoint de API (allowlist)?
     * Fuente única para `httpInput` y para `RedactSensitiveLogData`.
     */
    public static function isPathAllowedHost(string $host): bool
    {
        return self::providerForHost($host) !== 'other'
            && in_array(self::providerForHost($host), self::PATH_ALLOWED_PROVIDERS, true);
    }

    /**
     * Sólo S3 (`s3.amazonaws.com`, `s3.<region>…`, `s3-<region>…`, `<bucket>.s3…`):
     * API Gateway, Lambda URLs o ELB también viven en amazonaws.com y pueden
     * llevar el secreto en el path.
     */
    private static function isS3Host(string $host): bool
    {
        foreach (explode('.', $host) as $label) {
            if ($label === 's3' || str_starts_with($label, 's3-')) {
                return true;
            }
        }

        return false;
    }

    private static function providerForHost(string $host): string
    {
        $host = strtolower($host);

        foreach (self::PROVIDERS as $suffix => $name) {
            if (($host === $suffix || str_ends_with($host, '.'.$suffix)) && ($name !== 's3' || self::isS3Host($host))) {
                return $name;
            }
        }

        return 'other';
    }

    /**
     * @return array{provider: string, method: string, host: string, path?: string, path_hash?: string}
     */
    private static function httpInput(string $url, string $method): array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $provider = self::providerForHost($host);

        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');

        return [
            'provider' => $provider,
            'method' => strtoupper($method),
            'host' => $host,
        ] + (in_array($provider, self::PATH_ALLOWED_PROVIDERS, true)
            ? ['path' => $path]
            : ['path_hash' => substr(hash('sha256', $path), 0, 12)]);
    }

    /**
     * HMAC con la app key: correlaciona intentos contra el mismo login sin que
     * un sha256 pelado permita recuperar el email probando una lista.
     */
    private static function fingerprint(mixed $identifier): ?string
    {
        return is_string($identifier) && $identifier !== ''
            ? substr(hash_hmac('sha256', strtolower(trim($identifier)), (string) config('app.key')), 0, 12)
            : null;
    }
}
