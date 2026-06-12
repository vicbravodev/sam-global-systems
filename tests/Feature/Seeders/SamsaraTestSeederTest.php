<?php

namespace Tests\Feature\Seeders;

use App\Domains\Context\Listeners\RequestPanicMediaOnContextBuilt;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Models\Team;
use Database\Seeders\SamsaraTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SamsaraTestSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_runs_on_a_fresh_database_and_enables_panic_media_auto_request(): void
    {
        // Running on a pristine DB is the regression: the setting insert once
        // failed the NOT NULL constraint on updated_by_type during task fresh.
        $this->seed(SamsaraTestSeeder::class);

        $team = Team::query()->where('slug', 'serviexpress-jc')->firstOrFail();

        $setting = TenantSetting::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('setting_key', RequestPanicMediaOnContextBuilt::SETTING_KEY)
            ->firstOrFail();

        $this->assertTrue((bool) ($setting->value_json['value'] ?? false));

        // Idempotent re-run keeps a single row.
        $this->seed(SamsaraTestSeeder::class);

        $this->assertSame(1, TenantSetting::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('setting_key', RequestPanicMediaOnContextBuilt::SETTING_KEY)
            ->count());
    }
}
