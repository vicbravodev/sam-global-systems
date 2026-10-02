<?php

namespace App\Domains\Integrations\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Integrations\Enums\AuthType;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use Database\Factories\Domains\Integrations\TenantIntegrationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class TenantIntegration extends Model
{
    /** @use HasFactory<TenantIntegrationFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'team_id',
        'provider_id',
        'name',
        'status',
        'auth_type',
        'credentials_encrypted',
        'config_json',
        'sync_state_json',
        'last_sync_at',
        'last_location_poll_at',
        'last_telemetry_poll_at',
        'last_error_at',
        'last_error_message',
    ];

    protected $hidden = [
        'credentials_encrypted',
    ];

    /**
     * Top-level `config_json` keys that are safe to show in the browser and
     * editable from the integrations page. Everything else (provider tokens,
     * secrets a tenant pasted into the config) stays server-side.
     *
     * @var array<int, string>
     */
    public const array PUBLIC_CONFIG_KEYS = ['sync'];

    /**
     * The browser-safe slice of `config_json`, or null when there is none.
     *
     * @return array<string, mixed>|null
     */
    public function publicConfig(): ?array
    {
        $public = array_intersect_key($this->config_json ?? [], array_flip(self::PUBLIC_CONFIG_KEYS));

        return $public === [] ? null : $public;
    }

    /**
     * Applies a config edited from the page: it only ever saw the public keys,
     * so those are replaced by the submitted values while every hidden key
     * already stored is preserved instead of silently wiped.
     *
     * @param  array<string, mixed>  $submitted
     * @return array<string, mixed>
     */
    public function mergeSubmittedConfig(array $submitted): array
    {
        $hidden = array_diff_key($this->config_json ?? [], array_flip(self::PUBLIC_CONFIG_KEYS));

        return array_merge($hidden, $submitted);
    }

    /**
     * @return BelongsTo<IntegrationProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(IntegrationProvider::class, 'provider_id');
    }

    /**
     * @return HasMany<IntegrationCredential, $this>
     */
    public function credentials(): HasMany
    {
        return $this->hasMany(IntegrationCredential::class, 'tenant_integration_id');
    }

    /**
     * @return HasMany<IntegrationSyncJob, $this>
     */
    public function syncJobs(): HasMany
    {
        return $this->hasMany(IntegrationSyncJob::class, 'tenant_integration_id');
    }

    /**
     * @return HasOne<WebhookEndpoint, $this>
     */
    public function webhookEndpoint(): HasOne
    {
        return $this->hasOne(WebhookEndpoint::class, 'tenant_integration_id');
    }

    /**
     * Sólo integraciones de tenants vivos: un team dado de baja (soft-delete)
     * no debe seguir ingiriendo por los pollers del scheduler.
     *
     * @param  Builder<TenantIntegration>  $query
     */
    public function scopeOfLiveTeam(Builder $query): void
    {
        $query->whereHas('team');
    }

    public function isActive(): bool
    {
        return $this->status === TenantIntegrationStatus::Active;
    }

    /**
     * Escribe UNA sub-clave de `sync_state_json` sin pisar las demás.
     *
     * Varios pollers (safety events, alert incidents) guardan su estado en
     * sub-claves de la misma columna y corren a la vez: escribir el array
     * entero cargado al arrancar el job borraba lo que otro escribió mientras
     * tanto. Aquí se relee la fila bajo `lockForUpdate` dentro de una
     * transacción, se reemplaza sólo `$key` y se guarda junto con los
     * `$attributes` extra (p. ej. limpiar `last_error_*`). El modelo en
     * memoria queda con el estado fusionado.
     *
     * @param  array<string, mixed>  $value
     * @param  array<string, mixed>  $attributes
     */
    public function mergeSyncState(string $key, array $value, array $attributes = []): void
    {
        DB::transaction(function () use ($key, $value, $attributes): void {
            // Relectura de la propia fila (por su clave primaria) con lock.
            $current = static::withoutGlobalScopes()
                ->whereKey($this->getKey())
                ->where('team_id', $this->team_id)
                ->lockForUpdate()
                ->first();

            $state = $current?->sync_state_json ?? [];
            $state[$key] = $value;

            $this->forceFill([...$attributes, 'sync_state_json' => $state])->save();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantIntegrationStatus::class,
            'auth_type' => AuthType::class,
            'credentials_encrypted' => 'encrypted',
            'config_json' => 'array',
            'sync_state_json' => 'array',
            'last_sync_at' => 'datetime',
            'last_location_poll_at' => 'datetime',
            'last_telemetry_poll_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    protected static function newFactory(): TenantIntegrationFactory
    {
        return TenantIntegrationFactory::new();
    }
}
