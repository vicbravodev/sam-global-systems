<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Descripción de una excepción apta para logs: clase, código, mensaje
 * saneado y ubicación. Sustituye a todo `getMessage()` crudo, que puede
 * llevar bindings SQL, URLs firmadas, teléfonos o el prompt de la IA.
 */
final class SafeException
{
    private const int MAX_MESSAGE = 300;

    private const int MAX_FRAMES = 15;

    /**
     * @return array{class: string, code: int|string, message: string, at: string, previous?: string, sqlstate?: string, http_status?: int, trace?: list<string>}
     */
    public static function describe(Throwable $e, bool $withTrace = false): array
    {
        $description = [
            'class' => $e::class,
            'code' => $e->getCode(),
            'message' => self::message($e),
            'at' => self::relative($e->getFile()).':'.$e->getLine(),
        ];

        if ($e->getPrevious() !== null) {
            $description['previous'] = $e->getPrevious()::class;
        }

        if ($e instanceof QueryException) {
            $description['sqlstate'] = (string) ($e->errorInfo[0] ?? $e->getCode());
        }

        if ($e instanceof RequestException) {
            $description['http_status'] = $e->response->status();
        }

        if ($withTrace) {
            $description['trace'] = self::trace($e);
        }

        return $description;
    }

    private static function message(Throwable $e): string
    {
        // El mensaje de QueryException incluye los bindings: sólo el SQL con `?`.
        // El de RequestException, un resumen del body de la respuesta: sólo el status.
        $message = match (true) {
            $e instanceof QueryException => $e->getSql(),
            $e instanceof RequestException => "HTTP request returned status code {$e->response->status()}",
            default => $e->getMessage(),
        };

        return Str::limit(RedactSensitiveLogData::sanitize($message), self::MAX_MESSAGE - 1, '…');
    }

    /**
     * @return list<string>
     */
    private static function trace(Throwable $e): array
    {
        $frames = [];

        foreach (array_slice($e->getTrace(), 0, self::MAX_FRAMES) as $frame) {
            $call = ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '');
            $frames[] = isset($frame['file'])
                ? self::relative($frame['file']).':'.($frame['line'] ?? 0).' '.$call
                : '[internal] '.$call;
        }

        return $frames;
    }

    private static function relative(string $path): string
    {
        // Fuera de la app (tests unitarios) no hay base_path: cae al cwd.
        try {
            $base = rtrim(base_path(), '/').'/';
        } catch (Throwable) {
            $base = rtrim((string) getcwd(), '/').'/';
        }

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : ltrim($path, '/');
    }
}
