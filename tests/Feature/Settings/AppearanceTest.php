<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_appearance_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('appearance.edit'));

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page->component('settings/appearance'),
        );
    }

    public function test_guests_cannot_access_the_appearance_page(): void
    {
        $this->get(route('appearance.edit'))->assertRedirect(route('login'));
    }
}
