<?php

namespace Tests\Feature\Seeders;

use App\Domains\Tenancy\Models\Plan;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_super_admin_panel_fixtures(): void
    {
        $this->seed(DemoSeeder::class);

        $superAdmin = User::where('email', 'superadmin@sam.test')->first();
        $this->assertNotNull($superAdmin);
        $this->assertTrue($superAdmin->isSuperAdmin());
        $this->assertNotNull($superAdmin->personalTeam());

        $this->assertEqualsCanonicalizing(
            ['starter', 'pro', 'enterprise'],
            Plan::whereIn('code', ['starter', 'pro', 'enterprise'])
                ->pluck('code')->all(),
        );

        foreach (['acme-logistics', 'globex-transport', 'initech-freight'] as $slug) {
            $team = Team::where('slug', $slug)->first();
            $this->assertNotNull($team, "Missing tenant {$slug}");
            $this->assertFalse($team->is_personal);
            $this->assertDatabaseHas('team_subscriptions', ['team_id' => $team->id]);
        }
    }

    public function test_it_is_idempotent(): void
    {
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->assertSame(
            1,
            User::where('email', 'superadmin@sam.test')->count(),
        );
        $this->assertSame(
            1,
            Team::where('slug', 'acme-logistics')->count(),
        );
    }
}
