<?php

namespace App\Concerns;

use App\Support\SystemLog;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * Para jobs de media que leen/escriben en RustFS/S3: con el storage caído no
 * fallan ni gastan sus intentos, se re-encolan con backoff exponencial
 * (60 s → 30 min) hasta {@see OBJECT_STORAGE_RETRY_WINDOW_HOURS}. Un
 * `release()` no cuenta como excepción, así que el job debe acotar los demás
 * fallos con `$maxExceptions` en lugar de `$tries` (con `retryUntil()` Laravel
 * ignora `$tries`).
 *
 * @phpstan-require-implements ShouldQueue
 */
trait DefersOnObjectStorageOutage
{
    public const int OBJECT_STORAGE_FIRST_RETRY_SECONDS = 60;

    public const int OBJECT_STORAGE_MAX_RETRY_SECONDS = 1800;

    public const int OBJECT_STORAGE_RETRY_WINDOW_HOURS = 12;

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(self::OBJECT_STORAGE_RETRY_WINDOW_HOURS);
    }

    /**
     * Registra la degradación (`reason: storage_unavailable`) y re-encola el
     * job. El error va como `error:` (clase + mensaje redactado): nunca rutas
     * firmadas ni credenciales.
     *
     * @param  array<string, int|string|null>  $input
     */
    protected function deferForObjectStorageOutage(string $code, array $input, ?Throwable $error): void
    {
        $attempt = $this->attempts();
        $delay = self::objectStorageRetryDelay($attempt);

        SystemLog::degraded($code, reason: 'storage_unavailable', input: $input, calc: [
            'attempt' => $attempt,
            'retry_in_seconds' => $delay,
            'retry_window_hours' => self::OBJECT_STORAGE_RETRY_WINDOW_HOURS,
        ], error: $error);

        $this->release($delay);
    }

    /**
     * 60 s, 120 s, 240 s, … con tope de {@see OBJECT_STORAGE_MAX_RETRY_SECONDS}.
     */
    public static function objectStorageRetryDelay(int $attempt): int
    {
        $exponent = min(max($attempt - 1, 0), 10);

        return min(self::OBJECT_STORAGE_FIRST_RETRY_SECONDS * (2 ** $exponent), self::OBJECT_STORAGE_MAX_RETRY_SECONDS);
    }
}
