<?php

namespace Tests\Feature\Domains\Automation;

use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Enums\WorkflowStatus;
use App\Domains\Automation\Enums\WorkflowTriggerType;
use App\Domains\Automation\Models\ActionTemplate;
use App\Domains\Automation\Models\AutomationWorkflow;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * create_ticket y update_asset_state no hacen nada (ExecuteAction las cierra
 * como stub `deferred_v2`): no se ofrecen en el editor ni se aceptan al
 * guardar workflows o plantillas. El enum las conserva por los datos viejos.
 */
class DeferredActionTypesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);

        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;
    }

    /**
     * @return array<string, array{ActionType}>
     */
    public static function deferred(): array
    {
        return [
            'create_ticket' => [ActionType::CreateTicket],
            'update_asset_state' => [ActionType::UpdateAssetState],
        ];
    }

    public function test_enum_marks_exactly_the_two_stubbed_types_as_deferred(): void
    {
        $this->assertSame(['create_ticket', 'update_asset_state'], ActionType::deferredValues());
        $this->assertNotContains(ActionType::CreateTicket, ActionType::configurable());
        $this->assertNotContains(ActionType::UpdateAssetState, ActionType::configurable());
        $this->assertCount(count(ActionType::cases()) - 2, ActionType::configurable());
    }

    public function test_editor_options_do_not_offer_deferred_types(): void
    {
        $this->actingAs($this->user)
            ->get(route('automation.show', ['current_team' => $this->team->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('automation/index')
                ->has('options.actionTypes', count(ActionType::cases()) - 2)
                ->where('options.actionTypes', fn ($options): bool => collect($options)
                    ->pluck('value')
                    ->intersect(['create_ticket', 'update_asset_state'])
                    ->isEmpty()));
    }

    #[DataProvider('deferred')]
    public function test_store_workflow_rejects_deferred_type(ActionType $type): void
    {
        $this->actingAs($this->user)
            ->postJson("/api/{$this->team->slug}/automation/workflows", $this->workflowPayload($type))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['steps_json.1.action_type' => ActionType::DEFERRED_MESSAGE]);

        $this->assertSame(0, AutomationWorkflow::query()->where('team_id', $this->team->id)->count());
    }

    #[DataProvider('deferred')]
    public function test_update_workflow_rejects_deferred_type(ActionType $type): void
    {
        $workflow = AutomationWorkflow::factory()->create(['team_id' => $this->team->id]);
        $before = $workflow->steps_json;

        $this->actingAs($this->user)
            ->putJson("/api/{$this->team->slug}/automation/workflows/{$workflow->id}", [
                'steps_json' => $this->workflowPayload($type)['steps_json'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['steps_json.1.action_type' => ActionType::DEFERRED_MESSAGE]);

        $this->assertSame($before, $workflow->fresh()->steps_json);
    }

    #[DataProvider('deferred')]
    public function test_store_template_rejects_deferred_type(ActionType $type): void
    {
        $this->actingAs($this->user)
            ->postJson("/api/{$this->team->slug}/automation/templates", [
                'code' => 'tpl-diferida',
                'name' => 'Plantilla',
                'action_type' => $type->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['action_type' => ActionType::DEFERRED_MESSAGE]);

        $this->assertFalse(ActionTemplate::query()->where('code', 'tpl-diferida')->exists());
    }

    public function test_unknown_action_type_is_rejected_instead_of_crashing_at_runtime(): void
    {
        $payload = $this->workflowPayload(ActionType::SendEmail);
        $payload['steps_json'][1]['action_type'] = 'launch_rocket';

        $this->actingAs($this->user)
            ->postJson("/api/{$this->team->slug}/automation/workflows", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['steps_json.1.action_type']);
    }

    public function test_real_action_types_are_still_accepted(): void
    {
        $this->actingAs($this->user)
            ->postJson("/api/{$this->team->slug}/automation/workflows", $this->workflowPayload(ActionType::Escalate))
            ->assertCreated();

        $this->actingAs($this->user)
            ->postJson("/api/{$this->team->slug}/automation/templates", [
                'code' => 'tpl-correo',
                'name' => 'Plantilla',
                'action_type' => ActionType::SendEmail->value,
            ])
            ->assertCreated();
    }

    /**
     * @return array<string, mixed>
     */
    private function workflowPayload(ActionType $secondStep): array
    {
        return [
            'code' => 'wf-diferida',
            'name' => 'Workflow',
            'trigger_type' => WorkflowTriggerType::IncidentCreated->value,
            'status' => WorkflowStatus::Active->value,
            'steps_json' => [
                ['order' => 1, 'action_type' => ActionType::SendEmail->value, 'execution_mode' => 'async'],
                ['order' => 2, 'action_type' => $secondStep->value, 'execution_mode' => 'async'],
            ],
        ];
    }
}
