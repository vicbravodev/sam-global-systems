# Storage Infrastructure (RustFS)

## 1. Purpose

Configure RustFS as the S3-compatible object storage backend for all file operations in SAM, providing a unified contract for media, evidence, attachments, and exports.

## 2. Configuration

### Filesystem Disk

Add to `config/filesystems.php`:

```php
'rustfs' => [
    'driver' => 's3',
    'key' => env('RUSTFS_ACCESS_KEY', 'sail'),
    'secret' => env('RUSTFS_SECRET_KEY', 'password'),
    'region' => env('RUSTFS_REGION', 'us-east-1'),
    'bucket' => env('RUSTFS_BUCKET', 'sam'),
    'url' => env('RUSTFS_URL'),
    'endpoint' => env('RUSTFS_ENDPOINT', 'http://rustfs:9000'),
    'use_path_style_endpoint' => true,
    'throw' => true,
],
```

### Environment Variables

```
RUSTFS_ACCESS_KEY=sail
RUSTFS_SECRET_KEY=password
RUSTFS_ENDPOINT=http://rustfs:9000
RUSTFS_BUCKET=sam
RUSTFS_REGION=us-east-1
```

## 3. ObjectStorage Contract

```php
// app/Contracts/ObjectStorage.php
namespace App\Contracts;

interface ObjectStorage
{
    public function put(string $path, string|\Psr\Http\Message\StreamInterface $contents, array $options = []): void;
    public function get(string $path): string;
    public function delete(string $path): void;
    public function exists(string $path): bool;
    public function temporaryUrl(string $path, \DateTimeInterface $expiresAt, array $options = []): string;
    public function mimeType(string $path): ?string;
    public function size(string $path): ?int;
    public function url(string $path): string;
}
```

## 4. Implementation

```php
// app/Infrastructure/Storage/RustFsStorage.php
namespace App\Infrastructure\Storage;

use App\Contracts\ObjectStorage;
use Illuminate\Support\Facades\Storage;

final class RustFsStorage implements ObjectStorage
{
    public function __construct(
        private readonly string $disk = 'rustfs',
    ) {}

    public function put(string $path, string|\Psr\Http\Message\StreamInterface $contents, array $options = []): void
    {
        Storage::disk($this->disk)->put($path, $contents, $options);
    }

    public function get(string $path): string
    {
        return Storage::disk($this->disk)->get($path);
    }

    public function delete(string $path): void
    {
        Storage::disk($this->disk)->delete($path);
    }

    public function exists(string $path): bool
    {
        return Storage::disk($this->disk)->exists($path);
    }

    public function temporaryUrl(string $path, \DateTimeInterface $expiresAt, array $options = []): string
    {
        return Storage::disk($this->disk)->temporaryUrl($path, $expiresAt, $options);
    }

    public function mimeType(string $path): ?string
    {
        return Storage::disk($this->disk)->mimeType($path);
    }

    public function size(string $path): ?int
    {
        return Storage::disk($this->disk)->size($path);
    }

    public function url(string $path): string
    {
        return Storage::disk($this->disk)->url($path);
    }
}
```

## 5. Service Provider Binding

In `AppServiceProvider::register()`:

```php
$this->app->bind(
    \App\Contracts\ObjectStorage::class,
    \App\Infrastructure\Storage\RustFsStorage::class,
);
```

## 6. File Metadata Table

Track metadata for all stored objects in the database:

```php
// Migration: create_file_objects_table
Schema::create('file_objects', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->string('bucket');
    $table->string('object_key');
    $table->string('original_filename')->nullable();
    $table->unsignedBigInteger('size_bytes')->default(0);
    $table->string('content_type')->nullable();
    $table->string('checksum')->nullable();
    $table->string('visibility')->default('private'); // private|public
    $table->string('category');                        // media|evidence|attachment|export|avatar
    $table->nullableMorphs('fileable');                // Polymorphic: which entity owns this file
    $table->json('metadata_json')->nullable();
    $table->timestamps();

    $table->unique(['bucket', 'object_key']);
    $table->index(['team_id', 'category']);
    $table->index(['fileable_type', 'fileable_id']);
});
```

### FileObject Model

```php
// app/Domains/Tenancy/Models/FileObject.php (lives in Tenancy since it's cross-domain)
namespace App\Domains\Tenancy\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FileObject extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'team_id', 'bucket', 'object_key', 'original_filename',
        'size_bytes', 'content_type', 'checksum', 'visibility',
        'category', 'fileable_type', 'fileable_id', 'metadata_json',
    ];

    protected $casts = [
        'metadata_json' => 'array',
        'size_bytes' => 'integer',
    ];

    public function fileable(): MorphTo
    {
        return $this->morphTo();
    }
}
```

## 7. Path Convention

Organize objects by tenant and domain:

```
{team_id}/integrations/{integration_id}/{filename}
{team_id}/events/{event_id}/media/{filename}
{team_id}/incidents/{incident_id}/evidence/{filename}
{team_id}/reports/{report_id}/{filename}
```

## 8. Testing

Use `Storage::fake('rustfs')` in all tests that interact with file storage:

```php
Storage::fake('rustfs');
// perform upload action
Storage::disk('rustfs')->assertExists('1/events/42/media/clip.mp4');
```

## 9. Bucket Initialization

Create a command or seeder to ensure the default bucket exists in RustFS on first deploy:

```php
// php artisan storage:create-bucket
// Uses AWS SDK / S3 client to create the bucket if it doesn't exist
```
