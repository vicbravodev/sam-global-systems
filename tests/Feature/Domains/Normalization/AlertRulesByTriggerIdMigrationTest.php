<?php

namespace Tests\Feature\Domains\Normalization;

use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Normalization\Models\EventMappingRule;
use App\Domains\Normalization\Models\EventType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RefreshDatabase ya corrió la migración sobre una tabla vacía: el test siembra
 * las reglas como estaban antes y vuelve a correr `up()`.
 */
class AlertRulesByTriggerIdMigrationTest extends TestCase
{
    use RefreshDatabase;

    private IntegrationProvider $samsara;

    protected function setUp(): void
    {
        parent::setUp();

        $this->samsara = IntegrationProvider::factory()->samsara()->create();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_09_100000_map_samsara_alert_rules_by_trigger_id.php');
    }

    /**
     * @param  array<string, mixed>  $conditions
     */
    private function rule(string $typeCode, array $conditions): EventMappingRule
    {
        return EventMappingRule::factory()->create([
            'provider_id' => $this->samsara->id,
            'external_event_type' => 'AlertIncident',
            'external_conditions_json' => $conditions,
            'mapped_event_type_id' => EventType::factory()->create(['code' => $typeCode])->id,
            'priority' => 10,
        ]);
    }

    public function test_seeded_description_rules_become_trigger_id_rules(): void
    {
        $panic = $this->rule('panic_button', ['data.conditions.0.description' => 'Panic Button']);
        $tampering = $this->rule('tampering', ['data.conditions.0.description' => 'Tampering']);
        $camera = $this->rule('camera_obstructed', ['data.conditions.0.description' => 'Camera Obstructed']);

        $this->migration()->up();

        $this->assertSame(['data.conditions.*.triggerId' => 1034], $panic->fresh()?->external_conditions_json);
        $this->assertSame(20, $panic->fresh()?->priority);
        $this->assertSame(['data.conditions.*.triggerId' => 1045], $tampering->fresh()?->external_conditions_json);
        $this->assertSame(['data.conditions.*.description' => 'Camera Obstructed'], $camera->fresh()?->external_conditions_json);
    }

    public function test_rules_an_operator_changed_are_left_alone(): void
    {
        $custom = $this->rule('panic_button', ['data.conditions.0.description' => 'Botón SOS']);

        $this->migration()->up();

        $this->assertSame(['data.conditions.0.description' => 'Botón SOS'], $custom->fresh()?->external_conditions_json);
        $this->assertSame(10, $custom->fresh()?->priority);
    }

    public function test_up_is_idempotent_and_down_restores_the_description_rules(): void
    {
        $panic = $this->rule('panic_button', ['data.conditions.0.description' => 'Panic Button']);
        $migration = $this->migration();

        $migration->up();
        $migration->up();
        $this->assertSame(['data.conditions.*.triggerId' => 1034], $panic->fresh()?->external_conditions_json);

        $migration->down();
        $this->assertSame(['data.conditions.0.description' => 'Panic Button'], $panic->fresh()?->external_conditions_json);
    }
}
