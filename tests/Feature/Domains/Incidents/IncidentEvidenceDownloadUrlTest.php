<?php

namespace Tests\Feature\Domains\Incidents;

use App\Contracts\ObjectStorage;
use App\Domains\Incidents\Actions\AddIncidentEvidence;
use App\Domains\Incidents\Enums\EvidenceSourceType;
use App\Domains\Incidents\Enums\EvidenceType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentEvidence;
use App\Infrastructure\Storage\RustFsObjectStorage;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IncidentEvidenceDownloadUrlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
        $this->seed(IncidentsSeeder::class);

        Storage::fake('rustfs');
        $this->app->instance(ObjectStorage::class, new RustFsObjectStorage);
    }

    public function test_uploaded_evidence_does_not_persist_an_expiring_signed_url(): void
    {
        $incident = Incident::factory()->create();

        $evidence = app(AddIncidentEvidence::class)->execute(
            incident: $incident,
            evidenceType: EvidenceType::Image,
            sourceType: EvidenceSourceType::ManualUpload,
            title: 'Foto del operador',
            file: UploadedFile::fake()->image('foto.jpg'),
        );

        $this->assertNull($evidence->file_url);
        $this->assertNotNull($evidence->storage_path);
        Storage::disk('rustfs')->assertExists($evidence->storage_path);
    }

    public function test_download_url_is_signed_fresh_from_storage_path(): void
    {
        Storage::disk('rustfs')->put('1/incidents/1/evidence/foto.jpg', 'x');

        $evidence = IncidentEvidence::factory()->create([
            'storage_path' => '1/incidents/1/evidence/foto.jpg',
            'file_url' => 'https://expired.example/old-signed-url',
        ]);

        $url = $evidence->downloadUrl();

        $this->assertIsString($url);
        $this->assertNotSame('https://expired.example/old-signed-url', $url);
    }

    public function test_external_link_without_storage_path_is_returned_as_is(): void
    {
        $evidence = IncidentEvidence::factory()->create([
            'storage_path' => null,
            'file_url' => 'https://samsara.example/incident/42',
        ]);

        $this->assertSame('https://samsara.example/incident/42', $evidence->downloadUrl());
    }

    public function test_api_store_returns_a_download_url(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $incident = Incident::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user);

        $response = $this->postJson("/api/{$team->slug}/incidents/{$incident->id}/evidence", [
            'evidence_type' => EvidenceType::Image->value,
            'source_type' => EvidenceSourceType::ManualUpload->value,
            'file' => UploadedFile::fake()->image('foto.jpg'),
        ]);

        $response->assertCreated();
        $this->assertNull($response->json('data.file_url'));
        $this->assertIsString($response->json('data.download_url'));
    }
}
