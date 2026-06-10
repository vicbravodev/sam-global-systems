<?php

namespace App\Domains\Notifications\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Notifications\Enums\ChannelType;
use App\Models\User;
use Database\Factories\Domains\Notifications\NotificationReplyTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationReplyToken extends Model
{
    /** @use HasFactory<NotificationReplyTokenFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'notification_reply_tokens';

    protected $fillable = [
        'team_id',
        'incident_id',
        'notification_id',
        'user_id',
        'channel_type',
        'address',
        'token',
        'expires_at',
        'consumed_at',
        'consumed_action',
        'reply_payload_json',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel_type' => ChannelType::class,
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'reply_payload_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'incident_id');
    }

    /**
     * @return BelongsTo<Notification, $this>
     */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class, 'notification_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    protected static function newFactory(): NotificationReplyTokenFactory
    {
        return NotificationReplyTokenFactory::new();
    }
}
