<?php

namespace App\Infrastructure\Storage;

use App\Contracts\ObjectStorage;
use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

class RustFsObjectStorage implements ObjectStorage
{
    public function put(string $path, mixed $contents, array $options = []): void
    {
        Storage::disk('rustfs')->put($path, $contents, $options);
    }

    public function get(string $path): ?string
    {
        if (! Storage::disk('rustfs')->exists($path)) {
            return null;
        }

        return Storage::disk('rustfs')->get($path);
    }

    public function delete(string $path): void
    {
        Storage::disk('rustfs')->delete($path);
    }

    public function exists(string $path): bool
    {
        return Storage::disk('rustfs')->exists($path);
    }

    public function temporaryUrl(string $path, DateTimeInterface $expiresAt, array $options = []): string
    {
        return $this->temporaryUrlDisk()->temporaryUrl($path, $expiresAt, $options);
    }

    /**
     * Las URLs prefirmadas las consume el navegador, que no resuelve el
     * hostname interno de Docker del endpoint. SigV4 firma el header Host,
     * así que cuando hay un endpoint público configurado hay que firmar
     * contra él (reescribir la URL después de firmar invalidaría la firma).
     */
    private function temporaryUrlDisk(): Filesystem
    {
        $config = config('filesystems.disks.rustfs', []);
        $publicEndpoint = $config['public_endpoint'] ?? null;

        if (! is_string($publicEndpoint) || $publicEndpoint === '' || $publicEndpoint === ($config['endpoint'] ?? null)) {
            return Storage::disk('rustfs');
        }

        return Storage::build(array_merge($config, ['endpoint' => $publicEndpoint]));
    }

    public function mimeType(string $path): ?string
    {
        $mime = Storage::disk('rustfs')->mimeType($path);

        return $mime === false ? null : $mime;
    }

    public function size(string $path): ?int
    {
        if (! Storage::disk('rustfs')->exists($path)) {
            return null;
        }

        return Storage::disk('rustfs')->size($path);
    }

    public function url(string $path): string
    {
        return Storage::disk('rustfs')->url($path);
    }
}
