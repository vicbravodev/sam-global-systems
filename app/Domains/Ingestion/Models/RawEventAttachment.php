<?php

namespace App\Domains\Ingestion\Models;

use App\Domains\Ingestion\Enums\AttachmentType;
use Database\Factories\Domains\Ingestion\RawEventAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RawEventAttachment extends Model
{
    /** @use HasFactory<RawEventAttachmentFactory> */
    use HasFactory;

    protected $fillable = [
        'raw_event_id',
        'attachment_type',
        'storage_path',
        'mime_type',
        'size_bytes',
        'metadata_json',
    ];

    /**
     * @return BelongsTo<RawEvent, $this>
     */
    public function rawEvent(): BelongsTo
    {
        return $this->belongsTo(RawEvent::class, 'raw_event_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attachment_type' => AttachmentType::class,
            'metadata_json' => 'array',
            'size_bytes' => 'integer',
        ];
    }

    protected static function newFactory(): RawEventAttachmentFactory
    {
        return RawEventAttachmentFactory::new();
    }
}
