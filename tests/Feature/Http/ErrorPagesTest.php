<?php

namespace Tests\Feature\Http;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_route_renders_branded_404_page(): void
    {
        $this->get('/this-route-does-not-exist')
            ->assertNotFound()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('errors/error')
                    ->where('status', 404),
            );
    }

    public function test_regular_member_gets_branded_403_on_admin_console(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);
        $user = User::factory()->create();
        $team->members()->attach($user, ['role' => 'member']);
        $user->forceFill(['current_team_id' => $team->id])->save();

        $this->actingAs($user)
            ->get(route('admin.tenants.index'))
            ->assertForbidden()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('errors/error')
                    ->where('status', 403),
            );
    }

    public function test_server_error_renders_branded_page_when_debug_is_off(): void
    {
        config(['app.debug' => false]);

        Route::middleware('web')->get('/_test/boom', fn () => abort(500));

        $this->get('/_test/boom')
            ->assertStatus(500)
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('errors/error')
                    ->where('status', 500),
            );
    }

    public function test_json_requests_keep_the_default_error_response(): void
    {
        $this->getJson('/this-route-does-not-exist')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }
}
