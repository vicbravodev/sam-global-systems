<?php

namespace App\Contracts\AI\Exceptions;

use RuntimeException;

/**
 * Thrown by a `MediaAssessmentAgent` when the media fails validation before
 * any model call (not a supported image by magic bytes, or over the size
 * cap). The caller records it as a `low_quality` assessment at no cost.
 */
class MediaFileRejectedException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
