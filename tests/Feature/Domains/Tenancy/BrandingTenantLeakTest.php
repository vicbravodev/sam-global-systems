<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\Tenancy\Models\TenantBranding;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Marca del tenant (`settings/tenant-config/branding` y `/logo`): siempre se
 * resuelve por el team de la ruta. Un `team_id` ajeno en el payload, o un
 * slug ajeno, nunca escribe en la marca, los archivos ni el storage de otro.
 */
class BrandingTenantLeakTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    private User $user;

    private Team $team;

    private Team $other;

    private TenantBranding $foreignBranding;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);

        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;
        $this->other = Team::factory()->create();
        $this->foreignBranding = TenantBranding::factory()->create([
            'team_id' => $this->other->id,
            'display_name' => 'Marca de B',
            'primary_color' => '#111111',
            'logo_url' => "branding/{$this->other->id}/logo-b.png",
        ]);
    }

    public function test_update_ignores_a_foreign_team_id_in_the_payload(): void
    {
        $response = $this->assertNoTenantLeak(
            $this->team,
            fn () => $this->actingAs($this->user)->putJson(
                route('tenant-config.branding.update', ['current_team' => $this->team->slug]),
                ['team_id' => $this->other->id, 'display_name' => 'Marca propia', 'primary_color' => '#2563eb'],
            ),
        );

        $response->assertOk()->assertJsonPath('data.team_id', $this->team->id);
        $this->assertSame('Marca de B', $this->foreignBranding->fresh()->display_name);
        $this->assertSame('#111111', $this->foreignBranding->fresh()->primary_color);
        $this->assertDatabaseHas('tenant_brandings', ['team_id' => $this->team->id, 'display_name' => 'Marca propia']);
    }

    public function test_logo_upload_writes_only_under_the_route_team(): void
    {
        Storage::fake('rustfs');

        $response = $this->assertNoTenantLeak(
            $this->team,
            fn () => $this->actingAs($this->user)->post(
                route('tenant-config.branding.logo', ['current_team' => $this->team->slug]),
                ['team_id' => $this->other->id, 'logo' => UploadedFile::fake()->image('logo.png', 64, 64)],
            ),
        );

        $response->assertCreated();
        $this->assertStringStartsWith("branding/{$this->team->id}/", (string) $response->json('data.logoKey'));
        $this->assertSame([], Storage::disk('rustfs')->allFiles("branding/{$this->other->id}"));
        $this->assertSame("branding/{$this->other->id}/logo-b.png", $this->foreignBranding->fresh()->logo_url);
        $this->assertDatabaseMissing('file_objects', ['team_id' => $this->other->id]);
    }

    public function test_foreign_slug_is_rejected_for_update_and_logo(): void
    {
        Storage::fake('rustfs');

        $update = $this->actingAs($this->user)->putJson(
            route('tenant-config.branding.update', ['current_team' => $this->other->slug]),
            ['display_name' => 'Hijack'],
        );
        $this->assertContains($update->status(), [403, 404]);

        $logo = $this->actingAs($this->user)->postJson(
            route('tenant-config.branding.logo', ['current_team' => $this->other->slug]),
            ['logo' => UploadedFile::fake()->image('logo.png', 64, 64)],
        );
        $this->assertContains($logo->status(), [403, 404]);

        $this->assertSame('Marca de B', $this->foreignBranding->fresh()->display_name);
        $this->assertSame([], Storage::disk('rustfs')->allFiles());
        $this->assertDatabaseMissing('file_objects', ['team_id' => $this->other->id]);
    }
}
