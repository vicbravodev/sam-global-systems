<?php

namespace App\Domains\Notifications\Models;

use App\Concerns\BelongsToTenant;
use App\Models\User;
use Database\Factories\Domains\Notifications\PushSubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un navegador/dispositivo que aceptó avisos de SAM (Web Push). Vale para un
 * usuario dentro de un team: el driver sólo entrega avisos de ese team. Un
 * mismo endpoint es una sola fila; volver a suscribirlo la reasigna al
 * usuario y team actuales (navegador compartido, cambio de team).
 *
 * @property int $id
 * @property int $team_id
 * @property int $user_id
 * @property string $endpoint
 * @property string $endpoint_hash
 * @property string $public_key
 * @property string $auth_token
 * @property string $content_encoding
 * @property ?string $device_label
 * @property ?Carbon $last_used_at
 */
class PushSubscription extends Model
{
    /** @use HasFactory<PushSubscriptionFactory> */
    use BelongsToTenant, HasFactory;

    /** Dispositivos por usuario y team; al pasar el tope se borran los más viejos. */
    public const MAX_PER_USER = 10;

    protected $fillable = [
        'team_id',
        'user_id',
        'endpoint',
        'endpoint_hash',
        'public_key',
        'auth_token',
        'content_encoding',
        'device_label',
        'last_used_at',
    ];

    protected $hidden = ['endpoint', 'public_key', 'auth_token'];

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /**
     * ¿El usuario tiene al menos un dispositivo suscrito en ese team? Es la
     * "dirección" del canal de avisos al dispositivo: sin dispositivo no hay
     * a dónde mandar y el canal ni se intenta.
     */
    public static function existsFor(int $teamId, int $userId): bool
    {
        return self::query()
            ->where('team_id', $teamId)
            ->where('user_id', $userId)
            ->exists();
    }

    protected static function booted(): void
    {
        static::saving(function (self $subscription): void {
            $subscription->endpoint_hash = self::hashEndpoint($subscription->endpoint);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'auth_token' => 'encrypted',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function newFactory(): PushSubscriptionFactory
    {
        return PushSubscriptionFactory::new();
    }
}
