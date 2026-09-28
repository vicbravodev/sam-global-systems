<?php

namespace App\Infrastructure\Storage;

use RuntimeException;
use Throwable;

/**
 * A media download rejected by policy (scheme, host, size) or failed in
 * transport. Thrown by {@see SecureMediaDownloader}.
 */
class MediaDownloadException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
