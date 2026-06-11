<?php

namespace App\Domains\Notifications\Models;

use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Support\EncryptedChannelConfigCast;
use Database\Factories\Domains\Notifications\NotificationChannelFactory;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Channels a team can actually deliver through (Roadmap V2-B1): its own
     * active channels plus SAM's platform channels (`team_id = null`) that
     * the tenant has not switched off via `tenant_channel_toggles`.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUsableByTeam(Builder $query, int $teamId): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->where('team_id', $teamId)->orWhereNull('team_id'))
            ->whereNotExists(function ($sub) use ($teamId) {
                $sub->from('tenant_channel_toggles')
                    ->whereColumn('tenant_channel_toggles.notification_channel_id', 'notification_channels.id')
                    ->where('tenant_channel_toggles.team_id', $teamId)
                    ->where('tenant_channel_toggles.enabled', false);
            });
    }

    protected static function newFactory(): NotificationChannelFactory
    {
        return NotificationChannelFactory::new();
    }
}
