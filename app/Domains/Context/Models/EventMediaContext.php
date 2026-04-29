<?php

namespace App\Domains\Context\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Assets\Models\Asset;
use App\Domains\Context\Enums\MediaAvailabilityStatus;
use App\Domains\Context\Enums\MediaRetrievalStatus;
use App\Domains\Context\Enums\MediaRole;
use App\Domains\Context\Enums\MediaType;
use App\Domains\Ingestion\Models\RawEventAttachment;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Models\FileObject;
use Database\Factories\Domains\Context\EventMediaContextFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventMediaContext extends Model
{
    /** @use HasFactory<EventMediaContextFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'team_id',
        'normalized_event_id',
        'asset_id',
        'provider_id',
        'file_object_id',
        'source_attachment_id',
        'media_type',
        'media_role',
        'media_url',
        'thumbnail_url',
        'storage_path',
        'duration_seconds',
        'size_bytes',
        'mime_type',
        'captured_at',
        'window_start',
        'window_end',
        'availability_status',
        'retrieval_status',
        'checksum',
        'metadata_json',
    ];

    /**
     * @return BelongsTo<NormalizedEvent, $this>
     */
    public function normalizedEvent(): BelongsTo
    {
        return $this->belongsTo(NormalizedEvent::class, 'normalized_event_id');
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    /**
     * @return BelongsTo<IntegrationProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(IntegrationProvider::class, 'provider_id');
    }

    /**
     * @return BelongsTo<FileObject, $this>
     */
    public function fileObject(): BelongsTo
    {
        return $this->belongsTo(FileObject::class, 'file_object_id');
    }

    /**
     * @return BelongsTo<RawEventAttachment, $this>
     */
    public function sourceAttachment(): BelongsTo
    {
        return $this->belongsTo(RawEventAttachment::class, 'source_attachment_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'media_type' => MediaType::class,
            'media_role' => MediaRole::class,
            'availability_status' => MediaAvailabilityStatus::class,
            'retrieval_status' => MediaRetrievalStatus::class,
            'metadata_json' => 'array',
            'captured_at' => 'datetime',
            'window_start' => 'datetime',
            'window_end' => 'datetime',
            'duration_seconds' => 'integer',
            'size_bytes' => 'integer',
        ];
    }

    protected static function newFactory(): EventMediaContextFactory
    {
        return EventMediaContextFactory::new();
    }
}
