<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\Tenancy\Models\FileObject;
use App\Domains\Tenancy\Models\TenantBranding;
use App\Models\Team;
use App\Models\User;
use App\Support\ObjectStorageFailure;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToWriteFile;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\FakesObjectStorageOutage;
use Tests\TestCase;

/**
 * RustFS/S3 caído al subir el logo de la marca: antes salía un 500 crudo.
 * Ahora el tenant recibe un mensaje legible, la marca anterior queda intacta
 * y ops lo ve en el log como degradación de storage.
 */
class BrandingLogoStorageOutageTest extends TestCase
{
    use AssertsSystemLog;
    use FakesObjectStorageOutage;
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private TenantBranding $branding;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
        $this->fakeObjectStorage();

        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;

        $this->branding = TenantBranding::factory()->create([
            'team_id' => $this->team->id,
            'logo_url' => "branding/{$this->team->id}/logo-anterior.png",
        ]);
    }

    public function test_logo_upload_with_storage_down_returns_a_readable_error_and_keeps_previous_branding(): void
    {
        $this->objectStorageGoesDown();

        $response = $this->actingAs($this->user)->post(
            route('tenant-config.branding.logo', ['current_team' => $this->team->slug]),
            ['logo' => UploadedFile::fake()->image('logo.png', 200, 200)],
            ['Accept' => 'application/json'],
        );

        $response->assertStatus(503)
            ->assertJsonPath('message', 'No se pudo subir el logo. '.ObjectStorageFailure::USER_MESSAGE)
            ->assertJsonPath('errors.logo.0', 'No se pudo subir el logo. '.ObjectStorageFailure::USER_MESSAGE);

        $this->assertSame(
            "branding/{$this->team->id}/logo-anterior.png",
            $this->branding->fresh()?->logo_url,
        );
        $this->assertSame(0, FileObject::withoutGlobalScopes()->where('category', 'branding_logo')->count());

        $this->assertSystemLogged(
            'tenancy.branding.logo_upload_failed',
            fn (array $c) => $c['reason'] === 'storage_unavailable'
                && $c['input']['team_id'] === $this->team->id
                && $c['error']['class'] === UnableToWriteFile::class,
        );
        $entries = $this->systemLogEntries('tenancy.branding.logo_upload_failed');
        $this->assertCount(1, $entries);
        $this->assertSame('warning', $entries[0]['level']);

        $logged = (string) json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString('X-Amz-Signature', $logged);
        $this->assertStringNotContainsString('deadbeefsecret', $logged);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_logo_upload_succeeds_once_storage_is_back(): void
    {
        $this->objectStorageGoesDown();
        $this->objectStorageComesBack();

        $this->actingAs($this->user)->post(
            route('tenant-config.branding.logo', ['current_team' => $this->team->slug]),
            ['logo' => UploadedFile::fake()->image('logo.png', 200, 200)],
            ['Accept' => 'application/json'],
        )->assertCreated();

        $logoKey = (string) $this->branding->fresh()?->logo_url;
        $this->assertNotSame("branding/{$this->team->id}/logo-anterior.png", $logoKey);
        Storage::disk('rustfs')->assertExists($logoKey);
        $this->assertSystemNotLogged('tenancy.branding.logo_upload_failed');
    }
}
