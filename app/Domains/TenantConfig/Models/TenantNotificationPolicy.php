<?php

namespace App\Domains\TenantConfig\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\Domains\TenantConfig\TenantNotificationPolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantNotificationPolicy extends Model
{
    /** @use HasFactory<TenantNotificationPolicyFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'tenant_notification_policies';

    protected $fillable = [
        'team_id',
        'policy_code',
        'notification_type',
        'priority',
        'allowed_channels_json',
        'fallback_channels_json',
        'recipient_rules_json',
        'quiet_hours_json',
        'escalation_rules_json',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allowed_channels_json' => 'array',
            'fallback_channels_json' => 'array',
            'recipient_rules_json' => 'array',
            'quiet_hours_json' => 'array',
            'escalation_rules_json' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): TenantNotificationPolicyFactory
    {
        return TenantNotificationPolicyFactory::new();
    }
}
