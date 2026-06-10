<?php

namespace App\Domains\Notifications\Models;

use App\Concerns\BelongsToTenant;
use App\Models\User;
use Database\Factories\Domains\Notifications\NotificationReadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user read marker for a tenant notification. A notification is "unread"
 * for a user until a row exists for the (notification, user) pair.
 */
class NotificationRead extends Model
{
    /** @use HasFactory<NotificationReadFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'notification_reads';

    protected $fillable = [
        'team_id',
        'notification_id',
        'user_id',
        'read_at',
    ];

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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    protected static function newFactory(): NotificationReadFactory
    {
        return NotificationReadFactory::new();
    }
}
