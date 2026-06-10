<?php

namespace App\Domains\Notifications\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use Database\Factories\Domains\Notifications\NotificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'notifications';

    protected $fillable = [
        'team_id',
        'source_type',
        'source_reference_id',
        'notification_type',
        'priority',
        'status',
        'subject',
        'body_preview',
        'template_id',
        'triggered_by_type',
        'triggered_by_id',
        'event_key',
        'payload_json',
        'scheduled_at',
        'sent_at',
    ];

    /**
     * @return BelongsTo<NotificationTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'template_id');
    }

    /**
     * @return HasMany<NotificationRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(NotificationRecipient::class, 'notification_id');
    }

    /**
     * @return HasMany<NotificationDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class, 'notification_id');
    }

    /**
     * @return HasMany<NotificationRead, $this>
     */
    public function reads(): HasMany
    {
        return $this->hasMany(NotificationRead::class, 'notification_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_type' => NotificationSourceType::class,
            'priority' => NotificationPriority::class,
            'status' => NotificationStatus::class,
            'triggered_by_type' => NotificationTriggeredByType::class,
            'payload_json' => 'array',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    protected static function newFactory(): NotificationFactory
    {
        return NotificationFactory::new();
    }
}
