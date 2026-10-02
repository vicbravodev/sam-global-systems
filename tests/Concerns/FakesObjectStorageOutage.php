<?php

namespace Tests\Concerns;

use App\Contracts\ObjectStorage;
use App\Infrastructure\Storage\RustFsObjectStorage;
use DateTimeInterface;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use RuntimeException;

/**
 * RustFS/S3 caído a voluntad: el contrato `ObjectStorage` real sobre un disco
 * `rustfs` falso que, mientras está "caído", lanza las mismas excepciones de
 * Flysystem que el adaptador S3 con `throw => true` (conexión rechazada,
 * timeout, 5xx). El mensaje lleva una URL prefirmada para probar que nunca
 * llega a los logs.
 */
trait FakesObjectStorageOutage
{
    public bool $objectStorageDown = false;

    protected function fakeObjectStorage(): void
    {
        Storage::fake('rustfs');

        $test = $this;

        $this->app->instance(ObjectStorage::class, new class($test) implements ObjectStorage
        {
            private RustFsObjectStorage $inner;

            /**
             * @param  object{objectStorageDown: bool}  $test
             */
            public function __construct(private readonly object $test)
            {
                $this->inner = new RustFsObjectStorage;
            }

            private function guard(string $operation, string $path): void
            {
                if (! $this->test->objectStorageDown) {
                    return;
                }

                $previous = new RuntimeException('cURL error 7: Failed to connect to rustfs port 9000 https://rustfs:9000/sam/'.$path.'?X-Amz-Signature=deadbeefsecret');

                throw match ($operation) {
                    'exists' => UnableToCheckFileExistence::forLocation($path, $previous),
                    'get' => UnableToReadFile::fromLocation($path, $previous->getMessage(), $previous),
                    default => UnableToWriteFile::atLocation($path, $previous->getMessage(), $previous),
                };
            }

            public function put(string $path, mixed $contents, array $options = []): void
            {
                $this->guard('put', $path);
                $this->inner->put($path, $contents, $options);
            }

            public function get(string $path): ?string
            {
                $this->guard('get', $path);

                return $this->inner->get($path);
            }

            public function delete(string $path): void
            {
                $this->guard('delete', $path);
                $this->inner->delete($path);
            }

            public function exists(string $path): bool
            {
                $this->guard('exists', $path);

                return $this->inner->exists($path);
            }

            public function temporaryUrl(string $path, DateTimeInterface $expiresAt, array $options = []): string
            {
                $this->guard('get', $path);

                return $this->inner->temporaryUrl($path, $expiresAt, $options);
            }

            public function mimeType(string $path): ?string
            {
                $this->guard('get', $path);

                return $this->inner->mimeType($path);
            }

            public function size(string $path): ?int
            {
                $this->guard('get', $path);

                return $this->inner->size($path);
            }

            public function url(string $path): string
            {
                return $this->inner->url($path);
            }
        });
    }

    protected function objectStorageGoesDown(): void
    {
        $this->objectStorageDown = true;
    }

    protected function objectStorageComesBack(): void
    {
        $this->objectStorageDown = false;
    }
}
