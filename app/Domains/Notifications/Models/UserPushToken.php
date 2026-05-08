<?php

namespace App\Domains\Notifications\Models;

use App\Concerns\BelongsToTenant;
use App\Models\User;
use Database\Factories\Domains\Notifications\UserPushTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPushToken extends Model
{
    /** @use HasFactory<UserPushTokenFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'user_push_tokens';

    protected $fillable = [
        'team_id',
        'user_id',
        'platform',
        'token',
        'device_name',
        'last_used_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function newFactory(): UserPushTokenFactory
    {
        return UserPushTokenFactory::new();
    }
}
