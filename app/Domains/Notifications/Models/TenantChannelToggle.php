<?php

namespace App\Domains\Notifications\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\Domains\Notifications\TenantChannelToggleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-tenant switch over a platform (SAM-managed, `team_id = null`) channel
 * (Roadmap V2-B1): SAM provides the channels; the tenant only turns them on
 * or off. No row means enabled — globals are opt-out.
 */
class TenantChannelToggle extends Model
{
    /** @use HasFactory<TenantChannelToggleFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'team_id',
        'notification_channel_id',
        'enabled',
    ];

    /**
     * @return BelongsTo<NotificationChannel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class, 'notification_channel_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    protected static function newFactory(): TenantChannelToggleFactory
    {
        return TenantChannelToggleFactory::new();
    }
}
