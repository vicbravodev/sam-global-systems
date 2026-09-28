<?php

namespace App\Domains\AI\Support;

/**
 * Identifies the image formats the vision model accepts by their magic bytes
 * — never by the reported Content-Type/extension, which providers get wrong
 * (octet-stream snapshots, HTML error pages saved as `.jpg`).
 */
final class ImageSignature
{
    /** @var list<string> */
    public const array ACCEPTED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /**
     * Mime type detected from the first bytes, or null when not an accepted image.
     */
    public static function detect(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => 'image/png',
            str_starts_with($bytes, 'GIF87a'), str_starts_with($bytes, 'GIF89a') => 'image/gif',
            strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
    }
}
