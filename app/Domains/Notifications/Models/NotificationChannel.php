<?php

namespace App\Domains\Notifications\Models;

use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Support\EncryptedChannelConfigCast;
use Database\Factories\Domains\Notifications\NotificationChannelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationChannel extends Model
{
    /** @use HasFactory<NotificationChannelFactory> */
    use HasFactory;

    protected $table = 'notification_channels';

    protected $fillable = [
        'team_id',
        'code',
        'name',
        'provider',
        'channel_type',
        'config_json',
        'is_active',
        'supports_priority',
        'supports_template',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel_type' => ChannelType::class,
            'config_json' => EncryptedChannelConfigCast::class,
            'is_active' => 'boolean',
            'supports_priority' => 'boolean',
            'supports_template' => 'boolean',
        ];
    }

    protected static function newFactory(): NotificationChannelFactory
    {
        return NotificationChannelFactory::new();
    }
}
