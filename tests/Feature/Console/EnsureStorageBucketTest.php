<?php

namespace Tests\Feature\Console;

use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class EnsureStorageBucketTest extends TestCase
{
    public function test_it_creates_the_bucket_when_it_does_not_exist(): void
    {
        $client = Mockery::mock(S3Client::class);
        $client->shouldReceive('doesBucketExistV2')->once()->with('sam')->andReturnFalse();
        $client->shouldReceive('createBucket')->once()->with(['Bucket' => 'sam']);

        $this->fakeRustfsDisk($client);

        $this->artisan('storage:ensure-bucket')
            ->expectsOutputToContain('Bucket [sam] creado')
            ->assertSuccessful();
    }

    public function test_it_does_nothing_when_the_bucket_already_exists(): void
    {
        $client = Mockery::mock(S3Client::class);
        $client->shouldReceive('doesBucketExistV2')->once()->with('sam')->andReturnTrue();
        $client->shouldNotReceive('createBucket');

        $this->fakeRustfsDisk($client);

        $this->artisan('storage:ensure-bucket')
            ->expectsOutputToContain('ya existe')
            ->assertSuccessful();
    }

    public function test_it_fails_when_the_storage_backend_is_unreachable(): void
    {
        $client = Mockery::mock(S3Client::class);
        $client->shouldReceive('doesBucketExistV2')->once()->andThrow(new \RuntimeException('connection refused'));

        $this->fakeRustfsDisk($client);

        $this->artisan('storage:ensure-bucket')
            ->expectsOutputToContain('No se pudo asegurar el bucket [sam]')
            ->assertFailed();
    }

    public function test_it_fails_for_a_disk_without_bucket(): void
    {
        config()->set('filesystems.disks.local.bucket', null);

        $this->artisan('storage:ensure-bucket', ['--disk' => 'local'])
            ->expectsOutputToContain('no define un bucket')
            ->assertFailed();
    }

    public function test_it_fails_for_a_non_s3_disk(): void
    {
        config()->set('filesystems.disks.local.bucket', 'whatever');

        $this->artisan('storage:ensure-bucket', ['--disk' => 'local'])
            ->expectsOutputToContain('no es un disco S3')
            ->assertFailed();
    }

    private function fakeRustfsDisk(S3Client $client): void
    {
        config()->set('filesystems.disks.rustfs.bucket', 'sam');

        $adapter = Mockery::mock(AwsS3V3Adapter::class);
        $adapter->shouldReceive('getClient')->andReturn($client);

        Storage::set('rustfs', $adapter);
    }
}
