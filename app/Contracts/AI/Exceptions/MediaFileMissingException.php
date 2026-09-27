<?php

namespace App\Contracts\AI\Exceptions;

use RuntimeException;

/**
 * Thrown by a `MediaAssessmentAgent` when the media binary is not on storage.
 * The model is never called and the caller must not persist a permanent
 * assessment: the file may still land (late upload) and be assessed later.
 */
class MediaFileMissingException extends RuntimeException
{
    public static function forPath(?string $path): self
    {
        return new self('Media file not found on storage: '.($path ?? '(sin ruta)'));
    }
}
