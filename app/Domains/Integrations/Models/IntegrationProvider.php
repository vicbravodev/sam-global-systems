<?php

namespace App\Domains\Integrations\Models;

use App\Domains\Integrations\Enums\IntegrationProviderStatus;
use App\Domains\Integrations\Enums\IntegrationProviderType;
use Database\Factories\Domains\Integrations\IntegrationProviderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrationProvider extends Model
{
    /** @use HasFactory<IntegrationProviderFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'type',
        'status',
        'config_schema_json',
        'capabilities_json',
    ];

    /**
     * @return HasMany<TenantIntegration, $this>
     */
    public function tenantIntegrations(): HasMany
    {
        return $this->hasMany(TenantIntegration::class, 'provider_id');
    }

    public function isDeprecated(): bool
    {
        return $this->status === IntegrationProviderStatus::Deprecated;
    }

    /**
     * @param  Builder<IntegrationProvider>  $query
     * @return Builder<IntegrationProvider>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', IntegrationProviderStatus::Active);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => IntegrationProviderType::class,
            'status' => IntegrationProviderStatus::class,
            'config_schema_json' => 'array',
            'capabilities_json' => 'array',
        ];
    }

    protected static function newFactory(): IntegrationProviderFactory
    {
        return IntegrationProviderFactory::new();
    }
}
