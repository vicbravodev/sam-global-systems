<?php

namespace App\Domains\Notifications\Models;

use App\Domains\Notifications\Enums\ChannelType;
use Database\Factories\Domains\Notifications\NotificationTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    /** @use HasFactory<NotificationTemplateFactory> */
    use HasFactory;

    protected $table = 'notification_templates';

    protected $fillable = [
        'team_id',
        'code',
        'name',
        'channel_type',
        'event_type',
        'priority',
        'subject_template',
        'body_template',
        'variables_schema_json',
        'locale',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel_type' => ChannelType::class,
            'variables_schema_json' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): NotificationTemplateFactory
    {
        return NotificationTemplateFactory::new();
    }
}
