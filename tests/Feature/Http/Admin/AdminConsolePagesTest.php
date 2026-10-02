<?php

namespace Tests\Feature\Http\Admin;

use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Tenancy\Enums\BillingCycle;
use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Operadores, planes y auditoría: lo que cada pantalla le dice al operador
 * para decidir con seguridad.
 */
class AdminConsolePagesTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(array $attributes = []): User
    {
        return User::factory()->superAdmin()->create($attributes);
    }

    private function audit(Team $team, string $action, AuditCategory $category, string $summary, ?User $actor = null, array $metadata = []): void
    {
        app(RecordAuditEntry::class)->execute(
            actorType: $actor !== null ? AuditActorType::User : AuditActorType::System,
            actorId: $actor?->id,
            action: $action,
            category: $category,
            entityType: Team::class,
            entityId: $team->id,
            summary: $summary,
            teamId: $team->id,
            metadata: $metadata,
            signature: $action.':'.Str::uuid()->toString(),
        );
    }

    public function test_operators_show_who_is_you_and_who_lacks_two_factor(): void
    {
        // La consola exige 2FA a quien la usa: el operador sin 2FA es otro.
        $me = $this->superAdmin();
        $unsecured = User::factory()->create(['global_role' => 'super_admin']);

        $this->actingAs($me)
            ->get(route('admin.operators.index'))
            ->assertInertia(function (Assert $page) use ($me, $unsecured) {
                $rows = collect($page->toArray()['props']['operators'])->keyBy('id');
                $this->assertTrue($rows[$me->id]['isYou']);
                $this->assertTrue($rows[$me->id]['twoFactor']);
                $this->assertFalse($rows[$unsecured->id]['isYou']);
                $this->assertFalse($rows[$unsecured->id]['twoFactor']);
            });
    }

    public function test_promoting_an_unknown_email_explains_how_to_create_the_operator(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.operators.store'), ['email' => 'nadie@sam.mx'])
            ->assertSessionHasErrors(['email' => 'No hay una cuenta con ese correo. Crea la cuenta del operador con `php artisan sam:create-super-admin`.']);
    }

    public function test_plans_report_how_many_live_clients_each_one_affects(): void
    {
        $this->seed(PlanSeeder::class);
        $pro = Plan::query()->where('code', 'pro')->sole();

        foreach ([SubscriptionStatus::Active, SubscriptionStatus::Suspended, SubscriptionStatus::Canceled] as $status) {
            $team = Team::factory()->create(['is_personal' => false]);
            TenantContext::for($team->id, fn () => Subscription::query()->create([
                'team_id' => $team->id,
                'plan_id' => $pro->id,
                'status' => $status,
                'billing_cycle' => BillingCycle::Monthly,
                'starts_at' => now(),
            ]));
        }

        $this->actingAs($this->superAdmin())
            ->get(route('admin.plans.index'))
            ->assertInertia(function (Assert $page) use ($pro) {
                $rows = collect($page->toArray()['props']['plans'])->keyBy('id');
                $this->assertSame(2, $rows[$pro->id]['tenantsCount'], 'Las canceladas no cuentan.');
            });
    }

    public function test_audit_filters_by_category_client_and_search(): void
    {
        $admin = $this->superAdmin();
        $norte = Team::factory()->create(['is_personal' => false, 'name' => 'Norte']);
        $sur = Team::factory()->create(['is_personal' => false, 'name' => 'Sur']);
        $this->audit($norte, 'tenant.updated', AuditCategory::Security, 'Nombre de Norte cambiado', $admin);
        $this->audit($norte, 'tenant.invoice_paid', AuditCategory::Billing, 'Factura #1 pagada', $admin);
        $this->audit($sur, 'tenant.updated', AuditCategory::Security, 'Logo de Sur cambiado', $admin);

        $this->actingAs($admin)
            ->get(route('admin.audit.index', ['tenant' => $norte->slug]))
            ->assertInertia(fn (Assert $page) => $page->has('entries', 2)->where('filters.tenant', $norte->slug));

        $this->actingAs($admin)
            ->get(route('admin.audit.index', ['category' => 'billing']))
            ->assertInertia(fn (Assert $page) => $page->has('entries', 1)->where('entries.0.action', 'tenant.invoice_paid'));

        $this->actingAs($admin)
            ->get(route('admin.audit.index', ['q' => 'LOGO']))
            ->assertInertia(fn (Assert $page) => $page->has('entries', 1)->where('entries.0.team', 'Sur'));
    }

    public function test_audit_paginates_instead_of_silently_truncating(): void
    {
        $admin = $this->superAdmin();
        $team = Team::factory()->create(['is_personal' => false]);

        foreach (range(1, 55) as $i) {
            $this->audit($team, 'tenant.updated', AuditCategory::Security, "Cambio {$i}", $admin);
        }

        $this->actingAs($admin)
            ->get(route('admin.audit.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries', 50)
                ->where('pagination.total', 55)
                ->where('pagination.lastPage', 2));

        $this->actingAs($admin)
            ->get(route('admin.audit.index', ['page' => 2]))
            ->assertInertia(fn (Assert $page) => $page->has('entries', 5));
    }

    public function test_audit_resolves_the_actor_when_the_entry_lacks_its_email(): void
    {
        $admin = $this->superAdmin(['email' => 'victor@sam.mx']);
        $team = Team::factory()->create(['is_personal' => false]);
        $this->audit($team, 'tenant.invoice_voided', AuditCategory::Billing, 'Factura anulada', $admin);

        $this->actingAs($admin)
            ->get(route('admin.audit.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.0.actorEmail', 'victor@sam.mx')
                ->where('entries.0.actionLabel', 'Factura anulada'));
    }

    public function test_tenant_creation_is_labelled_in_spanish_in_the_audit(): void
    {
        $admin = $this->superAdmin();
        $team = Team::factory()->create(['is_personal' => false]);
        $this->audit($team, 'tenant.created', AuditCategory::Security, 'Alta', $admin, ['actor_email' => $admin->email]);

        $this->actingAs($admin)
            ->get(route('admin.audit.index'))
            ->assertInertia(fn (Assert $page) => $page->where('entries.0.actionLabel', 'Cliente dado de alta'));
    }
}
