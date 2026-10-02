<?php

namespace Tests\Feature\Domains\Normalization;

use App\Domains\Normalization\Models\EventMappingRule;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Las reglas de mapeo (`event_mapping_rules`) son GLOBALES: no llevan
 * team_id y MapExternalEventType las aplica a todos los tenants. Un tenant
 * que las edita reescribe la normalización de toda la plataforma (p.ej.
 * remapear el botón de pánico de Samsara). Sólo el super-admin las muta.
 */
class MappingRuleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);

        $this->owner = User::factory()->create();
        $this->team = $this->owner->currentTeam;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(EventMappingRule $existing): array
    {
        return [
            'provider_id' => $existing->provider_id,
            'external_event_type' => 'EdgePanicButton',
            'mapped_event_type_id' => $existing->mapped_event_type_id,
            'priority' => 250,
            'is_active' => true,
        ];
    }

    public function test_tenant_owner_cannot_create_mapping_rules_via_web(): void
    {
        $existing = EventMappingRule::factory()->create();

        $this->actingAs($this->owner)->postJson(
            route('rules.mapping.store', ['current_team' => $this->team->slug]),
            $this->payload($existing),
        )->assertForbidden();

        $this->assertSame(1, EventMappingRule::query()->count());
    }

    public function test_tenant_viewer_cannot_create_mapping_rules_via_api(): void
    {
        $existing = EventMappingRule::factory()->create();
        $viewer = User::factory()->create();
        $this->team->members()->attach($viewer, ['role' => TeamRole::Member->value]);

        $this->actingAs($viewer)->postJson(
            "/api/{$this->team->slug}/normalization/mapping-rules",
            $this->payload($existing),
        )->assertForbidden();

        $this->assertSame(1, EventMappingRule::query()->count());
    }

    public function test_tenant_cannot_update_mapping_rules(): void
    {
        $rule = EventMappingRule::factory()->create(['priority' => 10, 'is_active' => true]);

        $this->actingAs($this->owner)->putJson(
            route('rules.mapping.update', ['current_team' => $this->team->slug, 'mappingRule' => $rule->id]),
            ['is_active' => false],
        )->assertForbidden();

        $this->actingAs($this->owner)->putJson(
            "/api/{$this->team->slug}/normalization/mapping-rules/{$rule->id}",
            ['is_active' => false],
        )->assertForbidden();

        $this->assertTrue((bool) $rule->fresh()->is_active);
    }

    public function test_tenant_cannot_delete_mapping_rules(): void
    {
        $rule = EventMappingRule::factory()->create();

        $this->actingAs($this->owner)->deleteJson(
            route('rules.mapping.destroy', ['current_team' => $this->team->slug, 'mappingRule' => $rule->id]),
        )->assertForbidden();

        $this->actingAs($this->owner)->deleteJson(
            "/api/{$this->team->slug}/normalization/mapping-rules/{$rule->id}",
        )->assertForbidden();

        $this->assertNotNull($rule->fresh());
    }

    public function test_super_admin_can_create_update_and_delete_mapping_rules(): void
    {
        $existing = EventMappingRule::factory()->create();
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->postJson(
            route('rules.mapping.store', ['current_team' => $this->team->slug]),
            $this->payload($existing),
        )->assertCreated();

        $this->actingAs($admin)->putJson(
            route('rules.mapping.update', ['current_team' => $this->team->slug, 'mappingRule' => $existing->id]),
            ['priority' => 99],
        )->assertOk()->assertJsonPath('priority', 99);

        $this->actingAs($admin)->deleteJson(
            "/api/{$this->team->slug}/normalization/mapping-rules/{$existing->id}",
        )->assertNoContent();

        $this->assertNull($existing->fresh());
    }

    public function test_tenant_members_can_still_list_mapping_rules(): void
    {
        EventMappingRule::factory()->create();

        $this->actingAs($this->owner)
            ->getJson("/api/{$this->team->slug}/normalization/mapping-rules")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_rules_page_exposes_mapping_manage_flag_only_to_super_admin(): void
    {
        $this->actingAs($this->owner)
            ->get(route('rules.show', ['current_team' => $this->team->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('rules/index')
                ->where('canManageMappingRules', false));

        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->get(route('rules.show', ['current_team' => $this->team->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('rules/index')
                ->where('canManageMappingRules', true));
    }
}
