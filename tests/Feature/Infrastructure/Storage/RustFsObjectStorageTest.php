<?php

namespace Tests\Feature\Infrastructure\Storage;

use App\Contracts\ObjectStorage;
use App\Infrastructure\Storage\RustFsObjectStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RustFsObjectStorageTest extends TestCase
{
    private ObjectStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('rustfs');

        $this->storage = new RustFsObjectStorage;
    }

    public function test_put_stores_contents_on_rustfs_disk(): void
    {
        $this->storage->put('path/to/file.txt', 'hello world');

        Storage::disk('rustfs')->assertExists('path/to/file.txt');
        $this->assertSame('hello world', Storage::disk('rustfs')->get('path/to/file.txt'));
    }

    public function test_put_accepts_options_array(): void
    {
        $this->storage->put('typed/file.jpg', 'binary-data', ['ContentType' => 'image/jpeg']);

        Storage::disk('rustfs')->assertExists('typed/file.jpg');
    }

    public function test_get_returns_stored_contents(): void
    {
        Storage::disk('rustfs')->put('read/me.txt', 'contents');

        $this->assertSame('contents', $this->storage->get('read/me.txt'));
    }

    public function test_get_returns_null_for_missing_file(): void
    {
        $this->assertNull($this->storage->get('missing.txt'));
    }

    public function test_exists_reports_presence(): void
    {
        $this->storage->put('exists.txt', 'x');

        $this->assertTrue($this->storage->exists('exists.txt'));
        $this->assertFalse($this->storage->exists('absent.txt'));
    }

    public function test_delete_removes_file(): void
    {
        $this->storage->put('remove-me.txt', 'x');
        $this->storage->delete('remove-me.txt');

        Storage::disk('rustfs')->assertMissing('remove-me.txt');
    }

    public function test_size_returns_bytes_or_null(): void
    {
        $this->storage->put('sized.txt', 'twelve bytes');

        $this->assertSame(12, $this->storage->size('sized.txt'));
        $this->assertNull($this->storage->size('absent.txt'));
    }

    public function test_mime_type_resolves_from_upload(): void
    {
        $uploaded = UploadedFile::fake()->image('photo.jpg');
        Storage::disk('rustfs')->putFileAs('uploads', $uploaded, 'photo.jpg');

        $this->assertStringContainsString('image', (string) $this->storage->mimeType('uploads/photo.jpg'));
    }

    public function test_url_returns_string(): void
    {
        $this->storage->put('public.txt', 'x');

        $this->assertIsString($this->storage->url('public.txt'));
    }

    public function test_temporary_url_returns_string(): void
    {
        $this->storage->put('temp.txt', 'x');

        $url = $this->storage->temporaryUrl('temp.txt', now()->addMinutes(5));

        $this->assertIsString($url);
        $this->assertNotEmpty($url);
    }

    public function test_temporary_url_signs_against_public_endpoint_when_configured(): void
    {
        config()->set('filesystems.disks.rustfs', [
            'driver' => 's3',
            'key' => 'sail',
            'secret' => 'password',
            'region' => 'us-east-1',
            'bucket' => 'sam',
            'endpoint' => 'http://rustfs:9000',
            'public_endpoint' => 'http://localhost:9000',
            'use_path_style_endpoint' => true,
            'throw' => true,
        ]);

        $url = $this->storage->temporaryUrl('teams/1/events/171/media/clip.mp4', now()->addMinutes(30));

        $this->assertStringStartsWith('http://localhost:9000/sam/teams/1/events/171/media/clip.mp4', $url);
        $this->assertStringContainsString('X-Amz-Signature=', $url);
        $this->assertStringNotContainsString('rustfs:9000', $url);
    }

    public function test_temporary_url_uses_the_disk_when_no_public_endpoint_is_configured(): void
    {
        config()->set('filesystems.disks.rustfs.public_endpoint', null);

        $this->storage->put('temp.txt', 'x');

        $this->assertIsString($this->storage->temporaryUrl('temp.txt', now()->addMinutes(5)));
    }
}
