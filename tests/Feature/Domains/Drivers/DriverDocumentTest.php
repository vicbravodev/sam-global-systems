<?php

namespace Tests\Feature\Domains\Drivers;

use App\Domains\Drivers\Enums\DocumentStatus;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverDocument;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
    }

    public function test_it_marks_expired_documents_automatically(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $driver = Driver::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'first_name' => 'Test',
            'last_name' => 'Driver',
            'full_name' => 'Test Driver',
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $document = DriverDocument::factory()->create([
            'driver_id' => $driver->id,
            'expires_at' => now()->subMonth(),
            'status' => DocumentStatus::Valid,
        ]);

        $this->assertTrue(
            $document->isExpired(),
            'Document with expires_at in the past should report as expired via isExpired()',
        );

        $validDocument = DriverDocument::factory()->create([
            'driver_id' => $driver->id,
            'expires_at' => now()->addYear(),
            'status' => DocumentStatus::Valid,
        ]);

        $this->assertFalse(
            $validDocument->isExpired(),
            'Document with expires_at in the future should not report as expired',
        );
    }

    public function test_it_updates_driver_documents_via_api(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $driver = Driver::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'first_name' => 'Doc',
            'last_name' => 'Driver',
            'full_name' => 'Doc Driver',
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        DriverDocument::factory()->create(['driver_id' => $driver->id]);

        $this->actingAs($user);

        $response = $this->putJson("/api/{$team->slug}/drivers/{$driver->id}/documents", [
            'documents' => [
                [
                    'document_type' => 'license',
                    'document_number' => 'LIC-2026-001',
                    'issued_at' => '2025-01-15',
                    'expires_at' => '2027-01-15',
                    'status' => 'valid',
                ],
            ],
        ]);

        $response->assertOk();

        $this->assertEquals(
            1,
            DriverDocument::where('driver_id', $driver->id)->count(),
            'Old documents should be replaced with the 1 new document from the request',
        );

        $this->assertDatabaseHas('driver_documents', [
            'driver_id' => $driver->id,
            'document_number' => 'LIC-2026-001',
            'document_type' => 'license',
        ]);
    }
}
