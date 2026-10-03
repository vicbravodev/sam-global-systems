<?php

namespace Tests\Feature\Domains\Audit;

use App\Domains\Assets\Enums\AssetMonitoringState;
use App\Domains\Assets\Models\Asset;
use App\Domains\Audit\Actions\RecordEntityChange;
use App\Domains\Audit\Enums\ChangeActorType;
use App\Domains\Audit\Enums\ChangeType;
use App\Domains\Audit\Models\ChangeHistory;
use App\Domains\Automation\Models\AutomationWorkflow;
use App\Domains\Decisions\Models\DecisionRule;
use App\Domains\Drivers\Enums\DriverStatus;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentStatus;
use App\Models\Team;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Observador de `change_histories` (Spec 14 §4.4): sólo los campos de
 * `audit.tracked_changes` dejan fila, con el team de la entidad, y un fallo
 * del historial nunca rompe la escritura de negocio.
 */
class RecordTrackedChangesTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    public function test_incident_status_change_records_before_and_after(): void
    {
        $incident = Incident::factory()->open()->create();
        $resolved = IncidentStatus::factory()->create(['code' => IncidentStatusCode::Resolved->value, 'is_terminal' => true]);
        $openStatusId = $incident->incident_status_id;

        $incident->update(['incident_status_id' => $resolved->id]);

        $history = ChangeHistory::withoutGlobalScopes()->sole();
        $this->assertSame($incident->team_id, $history->team_id);
        $this->assertSame($incident->getMorphClass(), $history->entity_type);
        $this->assertSame($incident->id, $history->entity_id);
        $this->assertSame(ChangeType::StatusChanged, $history->change_type);
        $this->assertSame(ChangeActorType::System, $history->changed_by_type);
        $this->assertNull($history->changed_by_id);
        $this->assertSame(['incident_status_id'], $history->changed_fields_json);
        $this->assertSame(['incident_status_id' => $openStatusId], $history->before_json);
        $this->assertSame(['incident_status_id' => $resolved->id], $history->after_json);
    }

    public function test_user_driven_change_records_the_user_as_actor(): void
    {
        $user = User::factory()->create();
        $incident = Incident::factory()->create(['team_id' => $user->current_team_id]);

        $this->actingAs($user);
        $incident->forceFill(['claimed_by_user_id' => $user->id, 'claimed_at' => now()])->save();

        $history = ChangeHistory::withoutGlobalScopes()->sole();
        $this->assertSame(ChangeType::Reassigned, $history->change_type);
        $this->assertSame(ChangeActorType::User, $history->changed_by_type);
        $this->assertSame($user->id, $history->changed_by_id);
        $this->assertSame(['claimed_by_user_id'], $history->changed_fields_json);
    }

    public function test_enum_and_boolean_fields_are_stored_as_plain_values(): void
    {
        $asset = Asset::factory()->create(['monitoring_state' => AssetMonitoringState::Pending]);
        $driver = Driver::factory()->create(['status' => DriverStatus::Active]);
        $rule = DecisionRule::factory()->create(['team_id' => $asset->team_id, 'is_active' => true]);
        $workflow = AutomationWorkflow::factory()->create(['is_active' => true]);

        $asset->update(['monitoring_state' => AssetMonitoringState::Monitored]);
        $driver->update(['status' => DriverStatus::Suspended]);
        $rule->update(['is_active' => false]);
        $workflow->update(['is_active' => false]);

        $rows = ChangeHistory::withoutGlobalScopes()->orderBy('id')->get()->all();
        $this->assertCount(4, $rows);
        [$assetRow, $driverRow, $ruleRow, $workflowRow] = $rows;
        $this->assertSame(['monitoring_state' => 'monitored'], $assetRow->after_json);
        $this->assertSame(['monitoring_state' => 'pending'], $assetRow->before_json);
        $this->assertSame(['status' => 'suspended'], $driverRow->after_json);
        $this->assertSame(['is_active' => false], $ruleRow->after_json);
        $this->assertSame(ChangeType::ConfigChanged, $ruleRow->change_type);
        $this->assertSame(['is_active' => true], $workflowRow->before_json);
        $this->assertSame($workflow->team_id, $workflowRow->team_id);
    }

    public function test_untracked_field_changes_leave_no_history(): void
    {
        $asset = Asset::factory()->create();
        $incident = Incident::factory()->create();

        $asset->update(['name' => 'Otro nombre', 'last_seen_at' => now()]);
        $incident->update(['title' => 'Nuevo título', 'escalation_attempt' => 2]);

        $this->assertSame(0, ChangeHistory::withoutGlobalScopes()->count());
    }

    public function test_platform_rows_without_tenant_are_skipped(): void
    {
        $rule = DecisionRule::factory()->create(['team_id' => null, 'is_active' => true]);

        $rule->update(['is_active' => false]);

        $this->assertSame(0, ChangeHistory::withoutGlobalScopes()->count());
        $this->assertSystemLogged('audit.entity_change.skipped', fn (array $context): bool => $context['reason'] === 'platform_row');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_failing_history_write_never_breaks_the_business_write(): void
    {
        $this->mock(RecordEntityChange::class, fn (MockInterface $mock) => $mock->shouldReceive('execute')
            ->andThrow(new RuntimeException('change_histories no disponible')));

        $driver = Driver::factory()->create(['status' => DriverStatus::Active]);

        $driver->update(['status' => DriverStatus::Suspended]);

        $this->assertSame(DriverStatus::Suspended, $driver->fresh()?->status);
        $this->assertSame(0, ChangeHistory::withoutGlobalScopes()->count());
        $this->assertSystemLogged('audit.entity_change.record_failed', fn (array $context): bool => $context['reason'] === 'write_failed'
            && $context['input']['entity_id'] === $driver->id
            && $context['input']['changed_fields'] === ['status']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_history_belongs_to_the_entity_team_not_the_ambient_tenant(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $driver = Driver::factory()->create(['team_id' => $teamA->id, 'status' => DriverStatus::Active]);

        // Un proceso que corre en el contexto de B toca un driver de A: la
        // fila de historial es de A, nunca del tenant ambiente.
        TenantContext::for($teamB->id, function () use ($driver): void {
            $driver->update(['status' => DriverStatus::OffDuty]);
        });

        $this->assertSame($teamA->id, ChangeHistory::withoutGlobalScopes()->sole()->team_id);
    }

    public function test_recording_a_change_never_touches_another_tenant(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        Driver::factory()->create(['team_id' => $teamB->id]);
        $driver = Driver::factory()->create(['team_id' => $teamA->id, 'status' => DriverStatus::Active]);

        $this->assertNoTenantLeak($teamA, function () use ($driver): void {
            $driver->update(['status' => DriverStatus::Unavailable]);
        });

        $this->assertSame(1, ChangeHistory::withoutGlobalScopes()->where('team_id', $teamA->id)->count());
    }
}
