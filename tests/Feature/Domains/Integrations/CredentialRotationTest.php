<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Integrations\Actions\RotateCredentials;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CredentialRotationTest extends TestCase
{
    use RefreshDatabase;

    private function createCredential(): IntegrationCredential
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->create();

        $integration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'name' => 'Rotation Test',
            'auth_type' => 'api_key',
            'credentials_encrypted' => 'master-cred',
            'status' => 'active',
        ]);

        return IntegrationCredential::create([
            'tenant_integration_id' => $integration->id,
            'key' => 'api_key',
            'value_encrypted' => 'old-secret-value',
        ]);
    }

    public function test_it_rotates_credentials_without_downtime(): void
    {
        $credential = $this->createCredential();
        $originalId = $credential->id;

        $action = new RotateCredentials;
        $action->execute($credential, 'new-secret-value');

        $credential->refresh();

        $this->assertEquals(
            $originalId,
            $credential->id,
            'Credential rotation should update the existing record, not create a new one — zero downtime',
        );

        $this->assertEquals(
            'new-secret-value',
            $credential->value_encrypted,
            'Credential value_encrypted should contain the new rotated value',
        );
    }

    public function test_it_updates_rotated_at_timestamp(): void
    {
        $credential = $this->createCredential();

        $this->assertNull(
            $credential->rotated_at,
            'Credential rotated_at should be null before any rotation',
        );

        $action = new RotateCredentials;
        $action->execute($credential, 'another-new-value');

        $credential->refresh();

        $this->assertNotNull(
            $credential->rotated_at,
            'Credential rotated_at should be set after rotation',
        );

        $this->assertTrue(
            $credential->rotated_at->isToday(),
            'Credential rotated_at should be today after rotation',
        );
    }
}
