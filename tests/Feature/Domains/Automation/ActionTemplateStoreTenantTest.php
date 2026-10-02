<?php

namespace Tests\Feature\Domains\Automation;

use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Models\ActionTemplate;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * `POST api/{team}/automation/templates`: la plantilla siempre nace en el
 * team de la ruta. Un `team_id` ajeno en el payload se ignora y nada se
 * escribe en otro tenant (ActionTemplate no lleva el scope de tenant: el
 * controlador es la única barrera).
 */
class ActionTemplateStoreTenantTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);

        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;
    }

    public function test_store_ignores_a_foreign_team_id_and_writes_only_in_the_route_team(): void
    {
        $other = Team::factory()->create();
        $foreign = ActionTemplate::factory()->create([
            'team_id' => $other->id,
            'code' => 'tpl-compartido',
            'name' => 'Plantilla de B',
        ]);

        $response = $this->assertNoTenantLeak(
            $this->team,
            fn () => $this->actingAs($this->user)->postJson("/api/{$this->team->slug}/automation/templates", [
                'team_id' => $other->id,
                'code' => 'tpl-compartido',
                'name' => 'Plantilla propia',
                'action_type' => ActionType::SendEmail->value,
            ]),
        );

        $response->assertCreated()->assertJsonPath('data.team_id', $this->team->id);
        $this->assertSame(1, ActionTemplate::query()->where('team_id', $other->id)->count());
        $this->assertSame('Plantilla de B', $foreign->fresh()->name);
        $this->assertDatabaseHas('action_templates', [
            'team_id' => $this->team->id,
            'code' => 'tpl-compartido',
            'name' => 'Plantilla propia',
        ]);
    }

    public function test_store_through_a_foreign_slug_is_rejected_without_writing(): void
    {
        $other = Team::factory()->create();

        $response = $this->actingAs($this->user)->postJson("/api/{$other->slug}/automation/templates", [
            'code' => 'tpl-intruso',
            'name' => 'Intruso',
            'action_type' => ActionType::SendEmail->value,
        ]);

        $this->assertContains($response->status(), [403, 404]);
        $this->assertDatabaseMissing('action_templates', ['code' => 'tpl-intruso']);
    }
}
