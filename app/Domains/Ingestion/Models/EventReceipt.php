<?php

namespace App\Domains\Ingestion\Models;

use Database\Factories\Domains\Ingestion\EventReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventReceipt extends Model
{
    /** @use HasFactory<EventReceiptFactory> */
    use HasFactory;

    protected $fillable = [
        'raw_event_id',
        'received_via',
        'request_id',
        'source_ip',
        'user_agent',
        'http_status_returned',
        'signature_valid',
        'received_at',
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
            'signature_valid' => 'boolean',
            'received_at' => 'datetime',
            'metadata_json' => 'array',
            'http_status_returned' => 'integer',
        ];
    }

    protected static function newFactory(): EventReceiptFactory
    {
        return EventReceiptFactory::new();
    }
}
