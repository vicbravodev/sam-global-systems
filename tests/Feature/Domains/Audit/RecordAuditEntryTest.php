<?php

namespace Tests\Feature\Domains\Audit;

use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Actions\RecordEntityChange;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Audit\Enums\ChangeActorType;
use App\Domains\Audit\Enums\ChangeType;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Audit\Models\ChangeHistory;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordAuditEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_an_audit_log_with_metadata(): void
    {
        $team = Team::factory()->create();

        /** @var RecordAuditEntry $action */
        $action = $this->app->make(RecordAuditEntry::class);

        $log = $action->execute(
            actorType: AuditActorType::User,
            actorId: 42,
            action: 'access.role_assigned',
            category: AuditCategory::Security,
            entityType: 'App\\Models\\Membership',
            entityId: 100,
            summary: 'Granted owner to user 42 on team '.$team->id,
            teamId: $team->id,
            metadata: ['role' => 'owner'],
            ipAddress: '127.0.0.1',
            userAgent: 'phpunit',
            signature: 'unique-sig-1',
        );

        $this->assertNotNull($log);
        $this->assertSame($team->id, $log->team_id);
        $this->assertSame('access.role_assigned', $log->action);
        $this->assertSame(['role' => 'owner'], $log->metadata_json);
        $this->assertSame(AuditCategory::Security, $log->category);
        $this->assertSame(AuditActorType::User, $log->actor_type);
    }

    public function test_it_is_idempotent_on_duplicate_signature(): void
    {
        $team = Team::factory()->create();

        /** @var RecordAuditEntry $action */
        $action = $this->app->make(RecordAuditEntry::class);

        $first = $action->execute(
            actorType: AuditActorType::System,
            actorId: null,
            action: 'system.reindex',
            category: AuditCategory::System,
            entityType: 'App\\Models\\Team',
            entityId: $team->id,
            summary: 'reindex',
            teamId: $team->id,
            signature: 'duplicate-sig',
        );

        $second = $action->execute(
            actorType: AuditActorType::System,
            actorId: null,
            action: 'system.reindex',
            category: AuditCategory::System,
            entityType: 'App\\Models\\Team',
            entityId: $team->id,
            summary: 'reindex (retry)',
            teamId: $team->id,
            signature: 'duplicate-sig',
        );

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id, 'Duplicate signature must return the original row.');

        $this->assertSame(
            1,
            AuditLog::withoutGlobalScopes()
                ->where('team_id', $team->id)
                ->where('signature', 'duplicate-sig')
                ->count(),
        );
    }

    public function test_record_entity_change_persists_before_after(): void
    {
        $team = Team::factory()->create();

        /** @var RecordEntityChange $action */
        $action = $this->app->make(RecordEntityChange::class);

        $history = $action->execute(
            entityType: 'App\\Domains\\Drivers\\Models\\Driver',
            entityId: 17,
            changeType: ChangeType::StatusChanged,
            teamId: $team->id,
            changedByType: ChangeActorType::User,
            changedById: 99,
            before: ['status' => 'active'],
            after: ['status' => 'suspended'],
            changedFields: ['status'],
            reason: 'manual suspension',
        );

        $this->assertSame(['status' => 'active'], $history->before_json);
        $this->assertSame(['status' => 'suspended'], $history->after_json);
        $this->assertSame(['status'], $history->changed_fields_json);
        $this->assertSame(ChangeType::StatusChanged, $history->change_type);
        $this->assertSame('manual suspension', $history->reason);

        $this->assertSame(1, ChangeHistory::withoutGlobalScopes()
            ->where('entity_id', 17)
            ->count());
    }
}
