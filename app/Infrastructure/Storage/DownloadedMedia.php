<?php

namespace App\Infrastructure\Storage;

/**
 * A media file downloaded by {@see SecureMediaDownloader} into a local temp
 * file. The caller streams it to storage and MUST call `cleanup()`.
 */
final class DownloadedMedia
{
    public function __construct(
        public readonly string $path,
        public readonly int $size,
        public readonly ?string $contentType,
    ) {}

    /**
     * @return resource
     */
    public function stream()
    {
        $handle = fopen($this->path, 'rb');

        if ($handle === false) {
            throw new MediaDownloadException('No se pudo leer el archivo temporal de descarga.');
        }

        return $handle;
    }

    public function cleanup(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
