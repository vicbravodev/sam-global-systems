<?php

namespace App\Support;

use Aws\Exception\AwsException;
use Illuminate\Support\Facades\Log;
use League\Flysystem\FilesystemException;
use Throwable;

/**
 * Object storage (RustFS / S3) failures on user-facing requests: recognise
 * them, report them to ops and give the user a readable message instead of a
 * raw 500. Only the operation, exception class and ids are logged — never
 * credentials, signed URLs or file contents.
 */
final class ObjectStorageFailure
{
    public const USER_MESSAGE = 'El almacenamiento de archivos no está disponible en este momento. Vuelve a intentarlo en unos minutos.';

    public static function matches(Throwable $e): bool
    {
        return $e instanceof FilesystemException || $e instanceof AwsException;
    }

    /**
     * @param  array<string, int|string|null>  $context
     */
    public static function report(string $operation, Throwable $e, array $context = []): void
    {
        Log::error('Object storage failure: '.$operation, array_merge([
            'operation' => $operation,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ], $context));
    }
}
