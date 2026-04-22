<?php

namespace App\Domains\Integrations\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Integrations\Enums\WebhookEventStatus;
use Database\Factories\Domains\Integrations\WebhookEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookEvent extends Model
{
    /** @use HasFactory<WebhookEventFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'team_id',
        'provider_id',
        'event_type',
        'payload_json',
        'received_at',
        'processed_at',
        'status',
        'error_message',
    ];

    /**
     * @return BelongsTo<IntegrationProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(IntegrationProvider::class, 'provider_id');
    }

    public function markAsProcessing(): void
    {
        $this->update(['status' => WebhookEventStatus::Processing]);
    }

    public function markAsProcessed(): void
    {
        $this->update([
            'status' => WebhookEventStatus::Processed,
            'processed_at' => now(),
        ]);
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => WebhookEventStatus::Failed,
            'error_message' => $errorMessage,
        ]);
    }

    public function markAsInvalidSignature(): void
    {
        $this->update(['status' => WebhookEventStatus::InvalidSignature]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WebhookEventStatus::class,
            'payload_json' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): WebhookEventFactory
    {
        return WebhookEventFactory::new();
    }
}
