<?php

namespace App\Domains\Ingestion\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Ingestion\Enums\RawEventStatus;
use App\Domains\Integrations\Models\IntegrationProvider;
use Database\Factories\Domains\Ingestion\RawEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RawEvent extends Model
{
    /** @use HasFactory<RawEventFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'team_id',
        'event_source_id',
        'provider_id',
        'external_event_id',
        'event_type_raw',
        'payload_json',
        'headers_json',
        'received_at',
        'occurred_at',
        'deduplication_key',
        'status',
        'checksum',
        'processing_attempts',
        'last_processing_attempt_at',
    ];

    /**
     * @return BelongsTo<EventSource, $this>
     */
    public function eventSource(): BelongsTo
    {
        return $this->belongsTo(EventSource::class, 'event_source_id');
    }

    /**
     * @return BelongsTo<IntegrationProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(IntegrationProvider::class, 'provider_id');
    }

    /**
     * @return HasOne<EventReceipt, $this>
     */
    public function receipt(): HasOne
    {
        return $this->hasOne(EventReceipt::class, 'raw_event_id');
    }

    /**
     * @return HasOne<EventDeduplicationKey, $this>
     */
    public function deduplicationKey(): HasOne
    {
        return $this->hasOne(EventDeduplicationKey::class, 'raw_event_id');
    }

    /**
     * @return HasMany<RawEventAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(RawEventAttachment::class, 'raw_event_id');
    }

    public function markAsStatus(RawEventStatus $status): void
    {
        $this->update(['status' => $status]);
    }

    public function markAsPendingProcessing(): void
    {
        $this->update(['status' => RawEventStatus::PendingProcessing]);
    }

    public function markAsProcessing(): void
    {
        $this->update([
            'status' => RawEventStatus::Processing,
            'processing_attempts' => $this->processing_attempts + 1,
            'last_processing_attempt_at' => now(),
        ]);
    }

    public function markAsProcessed(): void
    {
        $this->update(['status' => RawEventStatus::Processed]);
    }

    public function markAsFailed(): void
    {
        $this->update([
            'status' => RawEventStatus::Failed,
            'last_processing_attempt_at' => now(),
        ]);
    }

    public function markAsDuplicate(): void
    {
        $this->update(['status' => RawEventStatus::DuplicateDetected]);
    }

    public function markAsInvalidSignature(): void
    {
        $this->update(['status' => RawEventStatus::InvalidSignature]);
    }

    public function markAsMalformed(): void
    {
        $this->update(['status' => RawEventStatus::Malformed]);
    }

    /**
     * @param  Builder<RawEvent>  $query
     * @return Builder<RawEvent>
     */
    public function scopeWithStatus(Builder $query, RawEventStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RawEventStatus::class,
            'payload_json' => 'array',
            'headers_json' => 'array',
            'received_at' => 'datetime',
            'occurred_at' => 'datetime',
            'last_processing_attempt_at' => 'datetime',
            'processing_attempts' => 'integer',
        ];
    }

    protected static function newFactory(): RawEventFactory
    {
        return RawEventFactory::new();
    }
}
