<?php

namespace App\Contracts;

use DateTimeInterface;
use Psr\Http\Message\StreamInterface;

interface ObjectStorage
{
    /**
     * Store a file at the given path.
     *
     * @param  string|StreamInterface|resource  $contents
     * @param  array<string, mixed>  $options  Flysystem/S3 options (e.g. ['ContentType' => 'image/jpeg', 'visibility' => 'private']).
     */
    public function put(string $path, mixed $contents, array $options = []): void;

    /**
     * Get the contents of a file. Returns null when the object does not exist.
     */
    public function get(string $path): ?string;

    /**
     * Delete a file at the given path.
     */
    public function delete(string $path): void;

    /**
     * Determine if a file exists at the given path.
     */
    public function exists(string $path): bool;

    /**
     * Generate a presigned URL that grants temporary access to a private object.
     *
     * @param  array<string, mixed>  $options
     */
    public function temporaryUrl(string $path, DateTimeInterface $expiresAt, array $options = []): string;

    /**
     * Resolve the MIME type of the stored object, or null if it cannot be determined.
     */
    public function mimeType(string $path): ?string;

    /**
     * Size of the stored object in bytes, or null if it cannot be determined.
     */
    public function size(string $path): ?int;

    /**
     * Resolve the public URL for a file at the given path.
     */
    public function url(string $path): string;
}
