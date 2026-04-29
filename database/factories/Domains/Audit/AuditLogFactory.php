<?php

namespace Database\Factories\Domains\Audit;

use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Audit\Models\AuditLog;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'actor_type' => AuditActorType::System,
            'actor_id' => null,
            'action' => 'system.recorded',
            'category' => AuditCategory::Domain,
            'entity_type' => 'App\\Domains\\Audit\\Models\\AuditLog',
            'entity_id' => null,
            'source_type' => null,
            'source_reference_id' => null,
            'signature' => 'audit-'.Str::uuid()->toString(),
            'summary' => $this->faker->sentence(),
            'metadata_json' => [],
            'ip_address' => null,
            'user_agent' => null,
            'occurred_at' => now(),
        ];
    }

    public function forUser(): static
    {
        return $this->state(fn () => [
            'actor_type' => AuditActorType::User,
            'category' => AuditCategory::Security,
        ]);
    }

    public function forSystemTrigger(): static
    {
        return $this->state(fn () => [
            'actor_type' => AuditActorType::System,
            'category' => AuditCategory::System,
        ]);
    }
}
