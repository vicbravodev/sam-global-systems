<?php

namespace App\Domains\Integrations\Models;

use Database\Factories\Domains\Integrations\WebhookEndpointFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WebhookEndpoint extends Model
{
    /** @use HasFactory<WebhookEndpointFactory> */
    use HasFactory;

    protected $fillable = [
        'tenant_integration_id',
        'url',
        'secret',
        'secret_configured_at',
        'status',
        'last_received_at',
        'last_valid_received_at',
        'last_rejected_at',
        'last_rejection_reason',
        'setup_mode',
        'setup_status',
        'setup_error',
        'provider_webhook_id',
        'provider_alert_configuration_id',
        'provisioned_at',
        'previous_secret',
        'previous_secret_expires_at',
    ];

    /** El cliente creó el webhook en Samsara y pegó la Secret Key. */
    public const string SETUP_MANUAL = 'manual';

    /** SAM creó el webhook y su alerta de pánico con el token del cliente. */
    public const string SETUP_AUTOMATIC = 'automatic';

    public const string SETUP_STATUS_PENDING = 'pending';

    public const string SETUP_STATUS_PROVISIONED = 'provisioned';

    /** El token no tiene Write Webhooks / Write Alerts: queda el flujo manual. */
    public const string SETUP_STATUS_MISSING_PERMISSIONS = 'missing_permissions';

    public const string SETUP_STATUS_FAILED = 'failed';

    /** Minutos que la Secret Key anterior sigue valiendo tras una rotación. */
    public const int ROTATION_GRACE_MINUTES = 10;

    /** Vocabulario de `signatureHealth()`. */
    public const string HEALTH_PENDING_SECRET = 'pending_secret';

    public const string HEALTH_REJECTING = 'rejecting';

    public const string HEALTH_WAITING = 'waiting';

    public const string HEALTH_OK = 'ok';

    /**
     * Rechazos más viejos que esto, sin ningún webhook válido, ya no se
     * consideran un episodio vivo (p. ej. un escaneo aislado a la URL).
     */
    public const int REJECTION_WINDOW_HOURS = 24;

    protected $hidden = [
        'secret',
        'previous_secret',
    ];

    /**
     * @return BelongsTo<TenantIntegration, $this>
     */
    public function tenantIntegration(): BelongsTo
    {
        return $this->belongsTo(TenantIntegration::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Samsara genera la Secret Key: hasta que el tenant la copia a SAM el
     * endpoint no puede validar nada y rechaza todo (fail-closed).
     */
    public function hasSecret(): bool
    {
        return $this->secret !== null && $this->secret !== '';
    }

    public function isProvisioned(): bool
    {
        return $this->setup_mode === self::SETUP_AUTOMATIC
            && $this->setup_status === self::SETUP_STATUS_PROVISIONED
            && $this->provider_webhook_id !== null;
    }

    /**
     * La Secret Key anterior a una rotación, mientras dure la gracia: Samsara
     * pudo firmar con ella entregas que siguen en vuelo.
     */
    public function previousSecretInGrace(): ?string
    {
        if ($this->previous_secret === null || $this->previous_secret === ''
            || $this->previous_secret_expires_at === null || $this->previous_secret_expires_at->isPast()) {
            return null;
        }

        return $this->previous_secret;
    }

    /**
     * Salud por firma, no por recepción: `pending_secret` (falta la llave),
     * `rejecting` (lo último que llegó se rechazó y no hubo válidos después,
     * dentro de `REJECTION_WINDOW_HOURS`), `ok` (llegó al menos uno válido) o
     * `waiting` (configurado, aún sin webhooks válidos).
     */
    public function signatureHealth(): string
    {
        if (! $this->hasSecret()) {
            return self::HEALTH_PENDING_SECRET;
        }

        if ($this->isRejecting()) {
            return self::HEALTH_REJECTING;
        }

        return $this->last_valid_received_at !== null ? self::HEALTH_OK : self::HEALTH_WAITING;
    }

    private function isRejecting(): bool
    {
        if ($this->last_rejected_at === null) {
            return false;
        }

        if ($this->last_valid_received_at !== null && $this->last_valid_received_at->gte($this->last_rejected_at)) {
            return false;
        }

        return $this->last_rejected_at->gt(now()->subHours(self::REJECTION_WINDOW_HOURS));
    }

    /**
     * Webhook con firma válida. Resolución de minuto (lo único que muestra la
     * UI): reescribir la misma fila caliente en cada webhook sólo añade
     * contención de locks.
     */
    public function recordValidDelivery(): void
    {
        $now = now();

        if ($this->last_valid_received_at !== null
            && $this->last_valid_received_at->gt($now->copy()->subMinute())
            && ($this->last_rejected_at === null || $this->last_valid_received_at->gte($this->last_rejected_at))) {
            return;
        }

        $this->forceFill(['last_received_at' => $now, 'last_valid_received_at' => $now])->save();
    }

    /**
     * Webhook rechazado (sin secret o firma inválida). Misma resolución de
     * minuto, salvo que cambie la razón o haya un válido más reciente.
     */
    public function recordRejection(string $reason): void
    {
        $now = now();

        if ($this->last_rejected_at !== null
            && $this->last_rejected_at->gt($now->copy()->subMinute())
            && $this->last_rejection_reason === $reason
            && ($this->last_valid_received_at === null || $this->last_rejected_at->gte($this->last_valid_received_at))) {
            return;
        }

        $this->forceFill([
            'last_received_at' => $now,
            'last_rejected_at' => $now,
            'last_rejection_reason' => $reason,
        ])->save();
    }

    protected static function booted(): void
    {
        static::creating(function (WebhookEndpoint $endpoint) {
            if (in_array($endpoint->url, [null, '', '0'], true)) {
                $endpoint->url = Str::uuid()->toString();
            }

            // Sin secret aleatorio: Samsara no lo conocería y rechazaríamos
            // todo sin saber por qué. `null` = pendiente de configurar.
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_received_at' => 'datetime',
            'last_valid_received_at' => 'datetime',
            'last_rejected_at' => 'datetime',
            'secret_configured_at' => 'datetime',
            'secret' => 'encrypted',
            'provisioned_at' => 'datetime',
            'previous_secret' => 'encrypted',
            'previous_secret_expires_at' => 'datetime',
        ];
    }

    protected static function newFactory(): WebhookEndpointFactory
    {
        return WebhookEndpointFactory::new();
    }
}
