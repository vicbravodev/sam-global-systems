<?php

namespace App\Domains\AI\Data;

/**
 * Structured input sent to the AI agent. Immutable DTO.
 */
final readonly class AIInputContext
{
    /**
     * @param  array<string, mixed>  $normalizedEvent
     * @param  array<string, mixed>  $contextSignals
     * @param  array<string, mixed>  $operationalProfile
     * @param  array<string, mixed>  $recentHistory
     * @param  array<string, mixed>  $tenantProfile
     */
    public function __construct(
        public int $teamId,
        public int $normalizedEventId,
        public array $normalizedEvent,
        public array $contextSignals,
        public array $operationalProfile,
        public array $recentHistory,
        public array $tenantProfile,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'team_id' => $this->teamId,
            'normalized_event_id' => $this->normalizedEventId,
            'normalized_event' => $this->normalizedEvent,
            'context_signals' => $this->contextSignals,
            'operational_profile' => $this->operationalProfile,
            'recent_history' => $this->recentHistory,
            'tenant_profile' => $this->tenantProfile,
        ];
    }
}
