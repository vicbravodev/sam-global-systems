<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Integrations\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WebhookSecretEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_secret_is_encrypted_at_rest(): void
    {
        $endpoint = WebhookEndpoint::factory()->create(['secret' => 'mi-secreto-real']);

        $raw = DB::table('webhook_endpoints')->where('id', $endpoint->id)->value('secret');

        $this->assertNotSame('mi-secreto-real', $raw, 'El secreto quedó en texto plano');
        $this->assertSame('mi-secreto-real', $endpoint->fresh()->secret, 'El descifrado transparente falló');
    }

    public function test_encrypt_command_is_idempotent(): void
    {
        $endpoint = WebhookEndpoint::factory()->create(['secret' => 'abc123']);

        $this->artisan('webhooks:encrypt-secrets')->assertSuccessful();
        $this->artisan('webhooks:encrypt-secrets')->assertSuccessful();

        $this->assertSame('abc123', $endpoint->fresh()->secret);
    }
}
