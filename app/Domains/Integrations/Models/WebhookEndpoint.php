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
        'status',
        'last_received_at',
    ];

    protected $hidden = [
        'secret',
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

    protected static function booted(): void
    {
        static::creating(function (WebhookEndpoint $endpoint) {
            if (empty($endpoint->url)) {
                $endpoint->url = Str::uuid()->toString();
            }

            if (empty($endpoint->secret)) {
                $endpoint->secret = Str::random(64);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_received_at' => 'datetime',
        ];
    }

    protected static function newFactory(): WebhookEndpointFactory
    {
        return WebhookEndpointFactory::new();
    }
}
