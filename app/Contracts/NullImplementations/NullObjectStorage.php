<?php

namespace App\Contracts\NullImplementations;

use App\Contracts\ObjectStorage;
use DateTimeInterface;

class NullObjectStorage implements ObjectStorage
{
    public function put(string $path, mixed $contents, array $options = []): void
    {
        // no-op
    }

    public function get(string $path): ?string
    {
        return null;
    }

    public function delete(string $path): void
    {
        // no-op
    }

    public function exists(string $path): bool
    {
        return false;
    }

    public function temporaryUrl(string $path, DateTimeInterface $expiresAt, array $options = []): string
    {
        return "https://null.storage/{$path}?expires=".$expiresAt->getTimestamp();
    }

    public function mimeType(string $path): ?string
    {
        return null;
    }

    public function size(string $path): ?int
    {
        return null;
    }

    public function url(string $path): string
    {
        return "https://storage.example.com/{$path}";
    }
}
