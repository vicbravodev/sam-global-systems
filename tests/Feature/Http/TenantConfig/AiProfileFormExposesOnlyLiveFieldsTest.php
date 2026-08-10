<?php

namespace Tests\Feature\Http\TenantConfig;

use App\Domains\TenantConfig\Models\TenantAIProfile;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Tarea 12: de los seis campos editables de TenantAIProfile
 * (profile_code/name/description aparte), sólo `automation_level` llega al
 * pipeline de IA — ver app/Domains/AI/Data/TenantAIProfileData.php, que sólo
 * transporta teamId/automationLevel/límites/modelo. `risk_tolerance`,
 * `false_positive_tolerance` y `media_strategy` se leían de la base y se
 * descartaban; `prompt_overrides_json` y `human_review_policy_json` nunca
 * llegaron a esta pantalla (el controlador no los exponía en `aiProfile`).
 *
 * Nota sobre la aserción: el brief original proponía `assertDontSee` sobre
 * los nombres de campo muertos, pero eso es un no-op aquí por dos motivos
 * independientes: (1) Inertia serializa las props como JSON camelCase
 * (`riskTolerance`, no `risk_tolerance`), así que el nombre snake_case del
 * campo nunca aparece literal en el body aunque la UI lo siga ofreciendo; y
 * (2) aunque `config/inertia.php` tiene SSR habilitado, no hay servidor SSR
 * levantado en el entorno de tests (Inertia cae a client-side rendering en
 * ese caso), así que ni siquiera las etiquetas visibles ("Tolerancia al
 * riesgo") se renderizan en el HTML de la respuesta inicial. La única señal
 * verificable desde PHPUnit es qué manda el controlador en las props de
 * Inertia — de ahí `assertInertia` + `has`/`missing` sobre
 * `aiProfileOptions`, el catálogo de opciones que sólo existía para
 * alimentar los `<select>` retirados (ver TenantConfigPageController::show
 * y AiTab en resources/js/pages/settings/tenant-config.tsx).
 */
class AiProfileFormExposesOnlyLiveFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dead_ai_fields_are_not_offered_to_the_tenant(): void
    {
        $this->seed(AccessSeeder::class);

        $user = User::factory()->create();
        $team = $user->currentTeam;

        $response = $this->actingAs($user)->get(
            route('tenant-config.show', ['current_team' => $team->slug]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('settings/tenant-config')
                ->has('aiProfileOptions', fn (Assert $options) => $options
                    ->has('automationLevels')
                    ->missing('riskTolerances')
                    ->missing('falsePositiveTolerances')
                    ->missing('mediaStrategies')),
        );
    }

    /**
     * Corrección post-revisión: el form del hub sólo manda automation_level
     * (ver AiTab en settings/tenant-config.tsx). Antes de esta corrección, el
     * form seguía re-enviando un snapshot de risk_tolerance/
     * false_positive_tolerance/media_strategy capturado al montar — si algo
     * escribía por el otro path activo (api.tenant-config.ai-profile.update,
     * mismo controlador, routes/api.php) mientras la pestaña seguía abierta,
     * el siguiente "Guardar perfil" lo pisaba con el valor viejo. Este test
     * reproduce exactamente ese escenario y confirma que ya no ocurre: un
     * update parcial que sólo manda automation_level no toca los otros tres
     * campos.
     */
    public function test_partial_update_preserves_dead_fields_written_via_the_api_path(): void
    {
        $this->seed(AccessSeeder::class);

        $user = User::factory()->create();
        $team = $user->currentTeam;

        // (a) Un cambio llega por el path API (mismo controlador, otra
        // ruta) con valores concretos en los tres campos muertos.
        $this->actingAs($user)->putJson(
            "/api/{$team->slug}/settings/ai-profile",
            [
                'profile_code' => 'custom',
                'name' => 'Perfil del tenant',
                'risk_tolerance' => 'high',
                'false_positive_tolerance' => 'low',
                'automation_level' => 'assisted',
                'media_strategy' => 'preferred',
            ],
        )->assertOk();

        // (b) El form del hub hace su propio guardado mandando SOLO lo que
        // la UI ofrece: automation_level (más los campos de identidad que sí
        // siguen editables ahí).
        $this->actingAs($user)->putJson(
            route('tenant-config.ai-profile.update', ['current_team' => $team->slug]),
            [
                'profile_code' => 'custom',
                'name' => 'Perfil del tenant',
                'automation_level' => 'highly_automated',
            ],
        )->assertOk();

        // (c) Los tres campos muertos conservan el valor escrito en (a); no
        // los pisó un snapshot stale ni un default.
        $profile = TenantAIProfile::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->firstOrFail();

        $this->assertSame('high', $profile->risk_tolerance->value);
        $this->assertSame('low', $profile->false_positive_tolerance->value);
        $this->assertSame('preferred', $profile->media_strategy->value);
        // El único campo que el form del hub controla sí se actualizó.
        $this->assertSame('highly_automated', $profile->automation_level->value);
    }
}
