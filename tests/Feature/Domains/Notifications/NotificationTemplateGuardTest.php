<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Automation\Models\ActionTemplate;
use App\Domains\Notifications\Models\NotificationTemplate;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Las plantillas globales (team_id null, sembradas por
 * NotificationTemplateSeeder) se usan para todos los tenants: un tenant no
 * puede reescribirlas.
 */
class NotificationTemplateGuardTest extends TestCase
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
    private function payload(): array
    {
        return [
            'code' => 'incident_created_email',
            'name' => 'Secuestrada',
            'channel_type' => 'email',
            'body_template' => 'Contenido reescrito por un tenant',
        ];
    }

    public function test_tenant_cannot_update_a_global_template(): void
    {
        $global = NotificationTemplate::factory()->create([
            'team_id' => null,
            'body_template' => 'Original',
        ]);

        $this->actingAs($this->owner)->putJson(
            "/api/{$this->team->slug}/notifications/templates/{$global->id}",
            $this->payload(),
        )->assertForbidden();

        $this->assertSame('Original', $global->fresh()->body_template);
    }

    public function test_tenant_cannot_update_another_tenants_template(): void
    {
        $foreign = NotificationTemplate::factory()->create(['body_template' => 'Ajena']);

        $this->actingAs($this->owner)->putJson(
            "/api/{$this->team->slug}/notifications/templates/{$foreign->id}",
            $this->payload(),
        )->assertForbidden();

        $this->assertSame('Ajena', $foreign->fresh()->body_template);
    }

    public function test_tenant_can_update_its_own_template(): void
    {
        $own = NotificationTemplate::factory()->create(['team_id' => $this->team->id]);

        $this->actingAs($this->owner)->putJson(
            "/api/{$this->team->slug}/notifications/templates/{$own->id}",
            $this->payload(),
        )->assertOk();

        $this->assertSame('Contenido reescrito por un tenant', $own->fresh()->body_template);
    }

    public function test_super_admin_can_update_a_global_template(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $global = NotificationTemplate::factory()->create(['team_id' => null]);

        $this->actingAs($admin)->putJson(
            "/api/{$this->team->slug}/notifications/templates/{$global->id}",
            $this->payload(),
        )->assertOk();
    }

    public function test_system_action_templates_are_not_editable_by_tenants(): void
    {
        $system = ActionTemplate::factory()->create(['team_id' => null]);

        $own = ActionTemplate::factory()->create(['team_id' => $this->team->id]);

        $this->actingAs($this->owner);

        $this->assertFalse($this->owner->can('update', $system));
        $this->assertTrue($this->owner->can('update', $own));
    }
}
