<?php

namespace App\Domains\TenantConfig\Data;

/**
 * Spec 16's view of how the Notifications module should route a given
 * `notification_type`/`priority` pair for a tenant.
 */
final readonly class ResolvedNotificationPolicy
{
    /**
     * @param  array<int, string>  $allowedChannels
     * @param  array<int, string>  $fallbackChannels
     * @param  array<string, mixed>|null  $recipientRules
     * @param  array<string, mixed>|null  $quietHours
     * @param  array<string, mixed>|null  $escalationRules
     */
    public function __construct(
        public int $teamId,
        public string $policyCode,
        public ?string $notificationType,
        public ?string $priority,
        public array $allowedChannels,
        public array $fallbackChannels = [],
        public ?array $recipientRules = null,
        public ?array $quietHours = null,
        public ?array $escalationRules = null,
        public bool $isPersisted = false,
    ) {}
}
