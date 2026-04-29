<?php

namespace App\Domains\Notifications\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Notifications\Enums\RecipientType;
use Database\Factories\Domains\Notifications\NotificationRecipientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationRecipient extends Model
{
    /** @use HasFactory<NotificationRecipientFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'notification_recipients';

    protected $fillable = [
        'notification_id',
        'team_id',
        'recipient_type',
        'recipient_reference_id',
        'name',
        'address',
        'channel_preference',
        'role',
        'metadata_json',
    ];

    /**
     * @return BelongsTo<Notification, $this>
     */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class, 'notification_id');
    }

    /**
     * @return HasMany<NotificationDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class, 'recipient_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipient_type' => RecipientType::class,
            'metadata_json' => 'array',
        ];
    }

    protected static function newFactory(): NotificationRecipientFactory
    {
        return NotificationRecipientFactory::new();
    }
}
