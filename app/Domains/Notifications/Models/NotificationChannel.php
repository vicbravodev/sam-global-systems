<?php

namespace App\Domains\Notifications\Models;

use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Support\EncryptedChannelConfigCast;
use Database\Factories\Domains\Notifications\NotificationChannelFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Canal de notificación de PLATAFORMA (catálogo global, sin `team_id`): SAM lo
 * opera para todos los tenants y cada tenant sólo puede apagarlo para su
 * equipo ({@see TenantChannelToggle}). No lleva `BelongsToTenant` porque no
 * es un dato de tenant.
 */
class NotificationChannel extends Model
{
    /** @use HasFactory<NotificationChannelFactory> */
    use HasFactory;

    protected $table = 'notification_channels';

    protected $fillable = [
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
     * Credenciales cifradas at rest: jamás deben salir en una serialización
     * (JSON/array) hacia el navegador o la API. Los resúmenes enmascarados se
     * construyen explícitamente donde se necesitan.
     *
     * @var list<string>
     */
    protected $hidden = [
        'config_json',
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
     * Channels a team can actually deliver through (Roadmap V2-B1): SAM's
     * active platform channels that the tenant has not switched off via
     * `tenant_channel_toggles`. Channels are platform-only — tenants never
     * own channels or messaging credentials.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUsableByTeam(Builder $query, int $teamId): Builder
    {
        return $query
            ->where('is_active', true)
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
