<?php

namespace App\Domains\Notifications\Models;

use App\Concerns\BelongsToTenant;
use App\Models\User;
use Database\Factories\Domains\Notifications\NotificationPreferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    /** @use HasFactory<NotificationPreferenceFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'notification_preferences';

    protected $fillable = [
        'team_id',
        'user_id',
        'role',
        'notification_type',
        'allowed_channels_json',
        'muted',
        'quiet_hours_json',
        'escalation_fallback_json',
    ];

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
            'allowed_channels_json' => 'array',
            'muted' => 'boolean',
            'quiet_hours_json' => 'array',
            'escalation_fallback_json' => 'array',
        ];
    }

    protected static function newFactory(): NotificationPreferenceFactory
    {
        return NotificationPreferenceFactory::new();
    }
}
