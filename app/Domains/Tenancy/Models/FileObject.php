<?php

namespace App\Domains\Tenancy\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\Domains\Tenancy\FileObjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FileObject extends Model
{
    /** @use HasFactory<FileObjectFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'team_id',
        'bucket',
        'object_key',
        'original_filename',
        'size_bytes',
        'content_type',
        'checksum',
        'visibility',
        'category',
        'fileable_type',
        'fileable_id',
        'metadata_json',
    ];

    protected $casts = [
        'metadata_json' => 'array',
        'size_bytes' => 'integer',
    ];

    public function fileable(): MorphTo
    {
        return $this->morphTo();
    }

    protected static function newFactory(): FileObjectFactory
    {
        return FileObjectFactory::new();
    }
}
