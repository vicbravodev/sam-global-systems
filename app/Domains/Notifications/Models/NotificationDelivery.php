<?php

namespace App\Domains\Notifications\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Notifications\Enums\DeliveryStatus;
use Database\Factories\Domains\Notifications\NotificationDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDelivery extends Model
{
    /** @use HasFactory<NotificationDeliveryFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'notification_deliveries';

    protected $fillable = [
        'notification_id',
        'recipient_id',
        'channel_id',
        'team_id',
        'provider_message_id',
        'status',
        'attempt_number',
        'payload_json',
        'response_json',
        'error_message',
        'sent_at',
        'delivered_at',
        'failed_at',
    ];

    /**
     * @return BelongsTo<Notification, $this>
     */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class, 'notification_id');
    }

    /**
     * @return BelongsTo<NotificationRecipient, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(NotificationRecipient::class, 'recipient_id');
    }

    /**
     * @return BelongsTo<NotificationChannel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class, 'channel_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'attempt_number' => 'integer',
            'payload_json' => 'array',
            'response_json' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): NotificationDeliveryFactory
    {
        return NotificationDeliveryFactory::new();
    }
}
