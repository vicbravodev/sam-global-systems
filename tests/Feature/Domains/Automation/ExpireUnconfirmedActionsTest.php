<?php

namespace Tests\Feature\Domains\Automation;

use App\Contracts\TenantConfig\TenantAutomationPoliciesResolver;
use App\Domains\Automation\Actions\ConfirmActionExecution;
use App\Domains\Automation\Actions\ExpireUnconfirmedActions;
use App\Domains\Automation\Data\TenantAutomationPolicies;
use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Enums\ActionLogType;
use App\Domains\Automation\Enums\ExecutionMode;
use App\Domains\Automation\Jobs\ExecuteActionJob;
use App\Domains\Automation\Jobs\ExpireUnconfirmedActionsJob;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Automation\Models\ActionExecutionLog;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class ExpireUnconfirmedActionsTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private const int DEFAULT_TTL = 30 * 60;

    protected function setUp(): void
    {
        parent::setUp();

        // Permisos de rol: confirmar exige `manage` sobre la ejecución.
        $this->seed(AccessSeeder::class);
        $this->freezeTime();
    }

    public function test_sweep_expires_pending_confirmation_past_its_ttl(): void
    {
        $team = Team::factory()->create();
        $execution = $this->pendingConfirmation($team, ageSeconds: self::DEFAULT_TTL + 60);

        (new ExpireUnconfirmedActionsJob)->handle(app(ExpireUnconfirmedActions::class));

        $fresh = $execution->fresh();
        $this->assertSame(ActionExecutionStatus::Cancelled, $fresh->status);
        $this->assertSame(ExpireUnconfirmedActions::EXPIRED_MESSAGE, $fresh->error_message);

        $log = ActionExecutionLog::query()->where('action_execution_id', $execution->id)->sole();
        $this->assertSame(ActionLogType::Warning, $log->log_type);
        $this->assertSame('confirmation_expired', $log->payload_json['reason']);
        $this->assertSame(self::DEFAULT_TTL, $log->payload_json['ttl_seconds']);
        $this->assertSame(self::DEFAULT_TTL + 60, $log->payload_json['age_seconds']);

        $this->assertSystemLogged('automation.action.expired', fn (array $c) => $c['input']['action_execution_id'] === $execution->id
            && $c['input']['team_id'] === $team->id
            && $c['calc']['ttl_seconds'] === self::DEFAULT_TTL
            && $c['calc']['age_seconds'] === self::DEFAULT_TTL + 60
            && $c['result']['status'] === 'cancelled');
        $this->assertSystemLogged('automation.confirmation_sweep.tenant_swept', fn (array $c) => $c['input']['team_id'] === $team->id
            && $c['result']['candidates_count'] === 1
            && $c['result']['expired_count'] === 1
            && $c['result']['race_lost_count'] === 0);
        $this->assertSystemLogged('automation.confirmation_sweep.completed', fn (array $c) => $c['result']['teams_count'] === 1
            && $c['result']['expired_count'] === 1);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_expiry_boundary_is_exactly_the_ttl(): void
    {
        $team = Team::factory()->create();
        $justBefore = $this->pendingConfirmation($team, ageSeconds: self::DEFAULT_TTL - 1);
        $exactly = $this->pendingConfirmation($team, ageSeconds: self::DEFAULT_TTL);
        $justAfter = $this->pendingConfirmation($team, ageSeconds: self::DEFAULT_TTL + 1);

        $counts = app(ExpireUnconfirmedActions::class)->execute($team->id);

        $this->assertSame(['candidates' => 2, 'expired' => 2, 'race_lost' => 0], $counts);
        $this->assertSame(ActionExecutionStatus::Pending, $justBefore->fresh()->status);
        $this->assertSame(ActionExecutionStatus::Cancelled, $exactly->fresh()->status);
        $this->assertSame(ActionExecutionStatus::Cancelled, $justAfter->fresh()->status);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_each_tenant_uses_its_own_ttl(): void
    {
        $shortTtl = Team::factory()->create();
        $defaultTtl = Team::factory()->create();
        $this->bindTtls([$shortTtl->id => 60]);

        $short = $this->pendingConfirmation($shortTtl, ageSeconds: 120);
        $default = $this->pendingConfirmation($defaultTtl, ageSeconds: 120);

        (new ExpireUnconfirmedActionsJob)->handle(app(ExpireUnconfirmedActions::class));

        $this->assertSame(ActionExecutionStatus::Cancelled, $short->fresh()->status);
        $this->assertSame(ActionExecutionStatus::Pending, $default->fresh()->status);
        $this->assertSystemLogged('automation.confirmation_sweep.tenant_swept', fn (array $c) => $c['input']['team_id'] === $shortTtl->id
            && $c['calc']['ttl_seconds'] === 60
            && $c['result']['expired_count'] === 1);
        $this->assertSystemLogged('automation.confirmation_sweep.tenant_swept', fn (array $c) => $c['input']['team_id'] === $defaultTtl->id
            && $c['calc']['ttl_seconds'] === self::DEFAULT_TTL
            && $c['result']['candidates_count'] === 0);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_non_positive_ttl_disables_expiry(): void
    {
        $team = Team::factory()->create();
        $this->bindTtls([$team->id => 0]);
        $execution = $this->pendingConfirmation($team, ageSeconds: 10 * 24 * 3600);

        $counts = app(ExpireUnconfirmedActions::class)->execute($team->id);

        $this->assertSame(0, $counts['expired']);
        $this->assertSame(ActionExecutionStatus::Pending, $execution->fresh()->status);
        $this->assertSystemLogged('automation.confirmation_sweep.tenant_swept', fn (array $c) => ($c['reason'] ?? null) === 'ttl_disabled'
            && $c['calc']['ttl_seconds'] === 0);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_terminal_and_non_confirmation_executions_are_never_touched(): void
    {
        $team = Team::factory()->create();
        $old = now()->subSeconds(self::DEFAULT_TTL * 10);

        $untouched = [];
        foreach ([
            ActionExecutionStatus::Queued,
            ActionExecutionStatus::Running,
            ActionExecutionStatus::Completed,
            ActionExecutionStatus::Failed,
            ActionExecutionStatus::Cancelled,
            ActionExecutionStatus::Retrying,
        ] as $status) {
            $untouched[] = ActionExecution::factory()->requiresConfirmation()->create([
                'team_id' => $team->id,
                'status' => $status,
                'error_message' => null,
                'created_at' => $old,
            ]);
        }

        // Pending pero sin compuerta de confirmación: no caduca.
        $untouched[] = ActionExecution::factory()->create([
            'team_id' => $team->id,
            'status' => ActionExecutionStatus::Pending,
            'execution_mode' => ExecutionMode::Async,
            'created_at' => $old,
        ]);

        (new ExpireUnconfirmedActionsJob)->handle(app(ExpireUnconfirmedActions::class));

        foreach ($untouched as $execution) {
            $fresh = $execution->fresh();
            $this->assertSame($execution->status, $fresh->status);
            $this->assertNull($fresh->error_message);
        }
        $this->assertSame(0, ActionExecutionLog::query()->count());
        $this->assertSystemNotLogged('automation.action.expired');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_concurrent_confirmation_wins_the_race(): void
    {
        Bus::fake();

        $team = Team::factory()->create();
        $created = $this->pendingConfirmation($team, ageSeconds: self::DEFAULT_TTL - 1);

        // El barrido leyó la fila (ya vencida para él) justo antes de que un
        // humano la confirmara.
        $staleCopy = ActionExecution::query()->whereKey($created->id)->sole();
        $this->assertSame(ConfirmActionExecution::CONFIRMED, app(ConfirmActionExecution::class)->execute($created));

        $this->travel(5)->seconds();
        $expire = app(ExpireUnconfirmedActions::class);
        $now = now();
        $won = $expire->expire($staleCopy, self::DEFAULT_TTL, $now->copy()->subSeconds(self::DEFAULT_TTL), $now);

        $this->assertFalse($won);
        $fresh = $staleCopy->fresh();
        $this->assertSame(ActionExecutionStatus::Queued, $fresh->status);
        $this->assertNull($fresh->error_message);
        $this->assertSame(0, ActionExecutionLog::query()->count());
        Bus::assertDispatchedTimes(ExecuteActionJob::class, 1);

        $this->assertSystemLogged('automation.action.expiry_skipped', fn (array $c) => $c['reason'] === 'state_changed'
            && $c['input']['action_execution_id'] === $created->id
            && $c['result']['current_status'] === 'queued');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_second_pass_is_a_no_op(): void
    {
        $team = Team::factory()->create();
        $execution = $this->pendingConfirmation($team, ageSeconds: self::DEFAULT_TTL + 1);

        $job = new ExpireUnconfirmedActionsJob;
        $job->handle(app(ExpireUnconfirmedActions::class));
        $updatedAt = $execution->fresh()->updated_at;

        $this->travel(2)->minutes();
        $job->handle(app(ExpireUnconfirmedActions::class));

        $this->assertSame(1, ActionExecutionLog::query()->where('action_execution_id', $execution->id)->count());
        $this->assertEquals($updatedAt, $execution->fresh()->updated_at);
        $this->assertCount(1, $this->systemLogEntries('automation.action.expired'));

        $sweeps = $this->systemLogEntries('automation.confirmation_sweep.completed');
        $this->assertCount(2, $sweeps);
        $this->assertSame(0, $sweeps[1]['context']['result']['teams_count']);
        $this->assertSame(0, $sweeps[1]['context']['result']['expired_count']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_confirm_endpoint_rejects_an_expired_execution(): void
    {
        Bus::fake();

        [$user, $team] = $this->member();
        $execution = $this->pendingConfirmation($team, ageSeconds: self::DEFAULT_TTL + 1);
        app(ExpireUnconfirmedActions::class)->execute($team->id);

        $this->actingAs($user)
            ->postJson("/api/{$team->slug}/automation/executions/{$execution->id}/confirm")
            ->assertStatus(422)
            ->assertJsonPath('message', 'El plazo para confirmar esta acción venció; ya no se ejecutará.');

        $this->assertSame(ActionExecutionStatus::Cancelled, $execution->fresh()->status);
        Bus::assertNotDispatched(ExecuteActionJob::class);
        $this->assertSystemLogged('automation.action.confirm_rejected', fn (array $c) => $c['reason'] === 'confirmation_expired'
            && $c['input']['action_execution_id'] === $execution->id
            && $c['calc']['race_lost'] === false);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_confirm_endpoint_expires_an_overdue_execution_before_the_sweep_runs(): void
    {
        Bus::fake();

        [$user, $team] = $this->member();
        $execution = $this->pendingConfirmation($team, ageSeconds: self::DEFAULT_TTL);

        $this->actingAs($user)
            ->postJson("/api/{$team->slug}/automation/executions/{$execution->id}/confirm")
            ->assertStatus(422);

        $fresh = $execution->fresh();
        $this->assertSame(ActionExecutionStatus::Cancelled, $fresh->status);
        $this->assertSame(ExpireUnconfirmedActions::EXPIRED_MESSAGE, $fresh->error_message);
        Bus::assertNotDispatched(ExecuteActionJob::class);
        $this->assertSystemLogged('automation.action.expired', fn (array $c) => $c['input']['action_execution_id'] === $execution->id);
        $this->assertSystemLogged('automation.action.confirm_rejected', fn (array $c) => $c['reason'] === 'confirmation_expired');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_confirm_endpoint_confirms_within_the_ttl(): void
    {
        Bus::fake();

        [$user, $team] = $this->member();
        $execution = $this->pendingConfirmation($team, ageSeconds: self::DEFAULT_TTL - 1);

        $this->actingAs($user)
            ->postJson("/api/{$team->slug}/automation/executions/{$execution->id}/confirm")
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued');

        Bus::assertDispatched(ExecuteActionJob::class, fn (ExecuteActionJob $job) => $job->actionExecutionId === $execution->id);
        $this->assertSystemLogged('automation.action.confirmed', fn (array $c) => $c['input']['action_execution_id'] === $execution->id
            && $c['calc']['ttl_seconds'] === self::DEFAULT_TTL
            && $c['result']['job_requested'] === true);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_confirm_rejects_a_manually_cancelled_execution_as_not_pending(): void
    {
        Bus::fake();

        [$user, $team] = $this->member();
        $execution = ActionExecution::factory()->requiresConfirmation()->create([
            'team_id' => $team->id,
            'status' => ActionExecutionStatus::Cancelled,
        ]);

        $this->actingAs($user)
            ->postJson("/api/{$team->slug}/automation/executions/{$execution->id}/confirm")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Solo las ejecuciones pendientes se pueden confirmar.');

        $this->assertSystemLogged('automation.action.confirm_rejected', fn (array $c) => $c['reason'] === 'not_pending'
            && $c['result']['current_status'] === 'cancelled');
        Bus::assertNotDispatched(ExecuteActionJob::class);
    }

    public function test_confirm_endpoint_cannot_touch_another_tenants_execution(): void
    {
        Bus::fake();

        [$user, $teamA] = $this->member();
        [, $teamB] = $this->member();

        $foreignOverdue = $this->pendingConfirmation($teamB, ageSeconds: self::DEFAULT_TTL + 60);
        $foreignLive = $this->pendingConfirmation($teamB, ageSeconds: 60);

        foreach ([$foreignOverdue, $foreignLive] as $foreign) {
            // Slug propio + id ajeno.
            $status = $this->actingAs($user)
                ->postJson("/api/{$teamA->slug}/automation/executions/{$foreign->id}/confirm")
                ->status();
            $this->assertContains($status, [403, 404], "Slug propio con id ajeno devolvió {$status}");

            // Slug del otro tenant (el usuario no es miembro de B).
            $status = $this->actingAs($user)
                ->postJson("/api/{$teamB->slug}/automation/executions/{$foreign->id}/confirm")
                ->status();
            $this->assertContains($status, [403, 404], "Slug ajeno devolvió {$status}");

            $fresh = $foreign->fresh();
            $this->assertSame(ActionExecutionStatus::Pending, $fresh->status);
            $this->assertNull($fresh->error_message);
        }

        $this->assertSame(0, ActionExecutionLog::query()->count());
        Bus::assertNotDispatched(ExecuteActionJob::class);
        $this->assertSystemNotLogged('automation.action.expired');
        $this->assertSystemNotLogged('automation.action.confirmed');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_confirm_action_inside_another_tenants_context_affects_nothing(): void
    {
        Bus::fake();

        $owner = Team::factory()->create();
        $intruder = Team::factory()->create();

        $overdue = $this->pendingConfirmation($owner, ageSeconds: self::DEFAULT_TTL + 60);
        $live = $this->pendingConfirmation($owner, ageSeconds: 60);

        $this->assertNoTenantLeak($intruder, function () use ($overdue, $live): void {
            $confirm = app(ConfirmActionExecution::class);

            $this->assertSame(ConfirmActionExecution::NOT_PENDING, $confirm->execute($overdue));
            $this->assertSame(ConfirmActionExecution::NOT_PENDING, $confirm->execute($live));
        });

        foreach ([$overdue, $live] as $execution) {
            $fresh = $execution->fresh();
            $this->assertSame(ActionExecutionStatus::Pending, $fresh->status);
            $this->assertNull($fresh->error_message);
        }
        $this->assertSame(0, ActionExecutionLog::query()->count());
        Bus::assertNotDispatched(ExecuteActionJob::class);
        $this->assertSystemNotLogged('automation.action.expired');
        $this->assertSystemNotLogged('automation.action.confirmed');
    }

    public function test_sweeping_one_tenant_never_touches_another(): void
    {
        $victim = Team::factory()->create();
        $swept = Team::factory()->create();

        $foreign = $this->pendingConfirmation($victim, ageSeconds: self::DEFAULT_TTL * 2);
        $own = $this->pendingConfirmation($swept, ageSeconds: self::DEFAULT_TTL * 2);

        $counts = $this->assertNoTenantLeak($swept, fn () => app(ExpireUnconfirmedActions::class)->execute($swept->id));

        $this->assertSame(1, $counts['expired']);
        $this->assertSame(ActionExecutionStatus::Cancelled, $own->fresh()->status);
        $this->assertSame(ActionExecutionStatus::Pending, $foreign->fresh()->status);
        $this->assertNull($foreign->fresh()->error_message);
        $this->assertSame(0, ActionExecutionLog::query()->where('action_execution_id', $foreign->id)->count());
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_platform_sweep_expires_each_tenant_inside_its_own_context(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $this->bindTtls([$teamA->id => 60]);

        $a = $this->pendingConfirmation($teamA, ageSeconds: 120);
        $b = $this->pendingConfirmation($teamB, ageSeconds: 120);

        (new ExpireUnconfirmedActionsJob)->handle(app(ExpireUnconfirmedActions::class));

        // El TTL corto de A no se filtra a B.
        $this->assertSame(ActionExecutionStatus::Cancelled, $a->fresh()->status);
        $this->assertSame(ActionExecutionStatus::Pending, $b->fresh()->status);
        $this->assertSystemLogged('automation.action.expired', fn (array $c) => $c['input']['team_id'] === $teamA->id);
        $this->assertCount(1, $this->systemLogEntries('automation.action.expired'));
    }

    public function test_sweep_is_scheduled_every_minute_on_one_server(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => $event instanceof CallbackEvent
                && str_contains((string) $event->description, ExpireUnconfirmedActionsJob::class));

        $this->assertNotNull($event, 'ExpireUnconfirmedActionsJob must be scheduled');
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
    }

    public function test_job_runs_on_the_automation_queue(): void
    {
        $this->assertSame('automation', (new ExpireUnconfirmedActionsJob)->queue);
    }

    private function pendingConfirmation(Team $team, int $ageSeconds): ActionExecution
    {
        return ActionExecution::factory()->requiresConfirmation()->create([
            'team_id' => $team->id,
            'created_at' => now()->subSeconds($ageSeconds),
            'updated_at' => now()->subSeconds($ageSeconds),
        ]);
    }

    /**
     * @return array{0: User, 1: Team}
     */
    private function member(): array
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->assertInstanceOf(Team::class, $team);

        return [$user, $team];
    }

    /**
     * Sustituye el resolver por uno con TTL por tenant (el resto, por defecto).
     *
     * @param  array<int, int>  $ttlByTeam
     */
    private function bindTtls(array $ttlByTeam): void
    {
        $this->app->instance(TenantAutomationPoliciesResolver::class, new class($ttlByTeam) implements TenantAutomationPoliciesResolver
        {
            /**
             * @param  array<int, int>  $ttlByTeam
             */
            public function __construct(private readonly array $ttlByTeam) {}

            public function resolve(int $teamId): TenantAutomationPolicies
            {
                $defaults = TenantAutomationPolicies::defaults();

                return new TenantAutomationPolicies(
                    automationLevel: $defaults->automationLevel,
                    maxRetries: $defaults->maxRetries,
                    retryBackoffSeconds: $defaults->retryBackoffSeconds,
                    confirmationTtlSeconds: $this->ttlByTeam[$teamId] ?? $defaults->confirmationTtlSeconds,
                );
            }
        });
    }
}
