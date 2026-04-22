<?php

namespace App\Domains\Integrations\Models;

use Database\Factories\Domains\Integrations\IntegrationCredentialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationCredential extends Model
{
    /** @use HasFactory<IntegrationCredentialFactory> */
    use HasFactory;

    protected $fillable = [
        'tenant_integration_id',
        'key',
        'value_encrypted',
        'expires_at',
        'rotated_at',
    ];

    protected $hidden = [
        'value_encrypted',
    ];

    /**
     * @return BelongsTo<TenantIntegration, $this>
     */
    public function tenantIntegration(): BelongsTo
    {
        return $this->belongsTo(TenantIntegration::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value_encrypted' => 'encrypted',
            'expires_at' => 'datetime',
            'rotated_at' => 'datetime',
        ];
    }

    protected static function newFactory(): IntegrationCredentialFactory
    {
        return IntegrationCredentialFactory::new();
    }
}
