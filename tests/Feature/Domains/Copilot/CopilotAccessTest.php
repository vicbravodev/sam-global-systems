<?php

namespace Tests\Feature\Domains\Copilot;

use App\Domains\Copilot\Models\CopilotConversation;
use App\Domains\Copilot\Models\CopilotMessage;
use App\Domains\Tenancy\Models\TenantFeature;
use Database\Seeders\AccessSeeder;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Who can use SAM Copilot, per tenant role and per tenant feature, like every
 * other module (spec 02 permissions + tenant features).
 */
class CopilotAccessTest extends TestCase
{
    use CopilotFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
        $this->seed(AIMeterSeeder::class);
    }

    #[DataProvider('rolesWithCopilot')]
    public function test_operational_roles_open_the_copilot_page(string $role): void
    {
        [$user, $team] = $this->memberWithRole($role);
        $asset = $this->truckWithTelemetry($team);

        $this->actingAs($user)
            ->get("/{$team->slug}/copilot")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('copilot/index')
                ->has('templates', 9)
                ->has('suggestions')
                ->where('assets.0.id', $asset->id)
                ->where('assets.0.code', 'T555')
                ->where('quota.used', 0)
                ->where('engine.mode', 'grounded')
                ->where('copilot.enabled', true));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rolesWithCopilot(): array
    {
        return [
            'tenant_admin' => ['tenant_admin'],
            'supervisor' => ['supervisor'],
            'monitorista' => ['monitorista'],
            'analyst' => ['analyst'],
        ];
    }

    #[DataProvider('rolesWithoutCopilot')]
    public function test_roles_without_copilot_use_are_blocked_everywhere(string $role): void
    {
        [$user, $team] = $this->memberWithRole($role);

        $this->actingAs($user)->get("/{$team->slug}/copilot")->assertForbidden();
        $this->actingAs($user)->getJson("/{$team->slug}/copilot/catalog")->assertForbidden();
        $this->actingAs($user)
            ->postJson("/{$team->slug}/copilot/messages", ['content' => '¿Dónde está T555?'])
            ->assertForbidden();

        $this->assertSame(0, CopilotMessage::query()->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rolesWithoutCopilot(): array
    {
        return [
            'viewer' => ['viewer'],
            'billing_manager' => ['billing_manager'],
        ];
    }

    public function test_disabling_the_copilot_feature_turns_the_module_off_for_the_tenant(): void
    {
        [$user, $team] = $this->memberWithRole('tenant_admin');
        TenantFeature::factory()->disabled()->create(['team_id' => $team->id, 'feature_key' => 'copilot']);

        $this->actingAs($user)->get("/{$team->slug}/copilot")->assertForbidden();
        $this->actingAs($user)
            ->postJson("/{$team->slug}/copilot/messages", ['content' => 'estado de la flota'])
            ->assertForbidden();

        $this->actingAs($user)
            ->get("/{$team->slug}/dashboard")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('copilot.enabled', false));
    }

    public function test_usage_dashboard_requires_the_usage_permission(): void
    {
        [$monitorista, $team] = $this->memberWithRole('monitorista');

        $this->actingAs($monitorista)
            ->postJson("/{$team->slug}/copilot/messages", ['content' => 'incidentes abiertos'])
            ->assertCreated();

        $this->actingAs($monitorista)->get("/{$team->slug}/copilot/usage")->assertForbidden();

        $billing = $this->joinTeamWithRole($team, 'billing_manager');

        $this->actingAs($billing)
            ->get("/{$team->slug}/copilot/usage?days=7")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('copilot/usage')
                ->where('usage.range.days', 7)
                ->where('usage.totals.queries', 1)
                ->where('usage.totals.activeUsers', 1)
                ->where('usage.byUser.0.userId', $monitorista->id)
                ->where('usage.recent.0.question', 'incidentes abiertos')
                ->has('usage.series', 7));
    }

    public function test_conversations_are_private_to_their_owner(): void
    {
        [$owner, $team] = $this->memberWithRole('supervisor');
        $conversation = CopilotConversation::factory()->create(['user_id' => $owner->id]);

        $colleague = $this->joinTeamWithRole($team, 'supervisor');

        $this->actingAs($colleague)
            ->getJson("/{$team->slug}/copilot/conversations/{$conversation->id}")
            ->assertForbidden();
        $this->actingAs($colleague)
            ->postJson("/{$team->slug}/copilot/messages", ['content' => 'hola', 'conversation_id' => $conversation->id])
            ->assertForbidden();

        $this->actingAs($owner)
            ->getJson("/{$team->slug}/copilot/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('conversation.id', $conversation->id);

        $this->actingAs($owner)
            ->patchJson("/{$team->slug}/copilot/conversations/{$conversation->id}", ['is_pinned' => true])
            ->assertOk()
            ->assertJsonPath('conversation.isPinned', true);

        $this->actingAs($owner)
            ->deleteJson("/{$team->slug}/copilot/conversations/{$conversation->id}")
            ->assertOk();
        $this->assertModelMissing($conversation);
    }

    public function test_plan_allowance_drives_the_monthly_quota(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        TenantFeature::factory()->withLimits(['included_quantity' => 2])->create([
            'team_id' => $team->id,
            'feature_key' => 'copilot_queries',
        ]);

        foreach (['flota', 'incidentes', 'ranking de conductores'] as $question) {
            $response = $this->actingAs($user)
                ->postJson("/{$team->slug}/copilot/messages", ['content' => $question])
                ->assertCreated();
        }

        // Soft limit: the third question is answered and counted as overage.
        $response->assertJsonPath('quota.used', 3)
            ->assertJsonPath('quota.included', 2)
            ->assertJsonPath('quota.overage', 1);
    }
}
