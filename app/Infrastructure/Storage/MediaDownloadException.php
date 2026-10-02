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
    /**
     * @param  string  $reason  código estable para el log: `ssrf_blocked`, `too_large`, `http_error`, `empty_body`, `transport_failed`, `temp_file_failed`
     */
    public function __construct(string $message, public readonly ?int $status = null, ?Throwable $previous = null, public readonly string $reason = 'transport_failed')
    {
        parent::__construct($message, 0, $previous);
    }
}
