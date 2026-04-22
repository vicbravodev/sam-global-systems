<?php

namespace App\Domains\Integrations\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Integrations\Enums\AuthType;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use Database\Factories\Domains\Integrations\TenantIntegrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'last_sync_at',
        'last_error_at',
        'last_error_message',
    ];

    protected $hidden = [
        'credentials_encrypted',
    ];

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

    public function isActive(): bool
    {
        return $this->status === TenantIntegrationStatus::Active;
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
            'last_sync_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    protected static function newFactory(): TenantIntegrationFactory
    {
        return TenantIntegrationFactory::new();
    }
}
