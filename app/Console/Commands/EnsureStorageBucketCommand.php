<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * RustFS no crea buckets automáticamente: con un volumen nuevo toda escritura
 * de media falla con NoSuchBucket hasta crearlo a mano. Este comando lo crea
 * de forma idempotente reutilizando la configuración del propio disco S3.
 */
class EnsureStorageBucketCommand extends Command
{
    protected $signature = 'storage:ensure-bucket
        {--disk=rustfs : Disco S3 de config/filesystems.php cuyo bucket asegurar}';

    protected $description = 'Crea el bucket S3 del disco indicado si no existe (idempotente)';

    public function handle(): int
    {
        $disk = (string) $this->option('disk');
        $bucket = config("filesystems.disks.{$disk}.bucket");

        if (! is_string($bucket) || $bucket === '') {
            $this->error("El disco [{$disk}] no define un bucket en config/filesystems.php.");

            return self::FAILURE;
        }

        $filesystem = Storage::disk($disk);

        if (! $filesystem instanceof AwsS3V3Adapter) {
            $this->error("El disco [{$disk}] no es un disco S3.");

            return self::FAILURE;
        }

        try {
            $client = $filesystem->getClient();

            if ($client->doesBucketExistV2($bucket)) {
                $this->info("El bucket [{$bucket}] ya existe en el disco [{$disk}].");

                return self::SUCCESS;
            }

            $client->createBucket(['Bucket' => $bucket]);
        } catch (Throwable $exception) {
            $this->error("No se pudo asegurar el bucket [{$bucket}] del disco [{$disk}]: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Bucket [{$bucket}] creado en el disco [{$disk}].");

        return self::SUCCESS;
    }
}
