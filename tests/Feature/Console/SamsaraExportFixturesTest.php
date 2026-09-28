<?php

namespace Tests\Feature\Console;

use App\Domains\Ingestion\Enums\EventSourceType;
use App\Domains\Ingestion\Models\EventSource;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SamsaraExportFixturesTest extends TestCase
{
    use RefreshDatabase;

    private string $output;

    protected function setUp(): void
    {
        parent::setUp();

        $this->output = storage_path('framework/testing/fixtures-'.uniqid());
        File::ensureDirectoryExists($this->output);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->output);

        parent::tearDown();
    }

    public function test_it_exports_real_panic_and_safety_events_without_secrets(): void
    {
        $team = Team::factory()->create(['slug' => 'flota-real']);
        $other = Team::factory()->create();
        $samsara = IntegrationProvider::factory()->samsara()->create();
        $webhook = EventSource::factory()->create(['team_id' => $team->id, 'provider_id' => $samsara->id, 'source_type' => EventSourceType::Webhook]);
        $feed = EventSource::factory()->create(['team_id' => $team->id, 'provider_id' => $samsara->id, 'source_type' => EventSourceType::PollingFeed]);

        RawEvent::factory()->create([
            'team_id' => $team->id,
            'event_source_id' => $webhook->id,
            'provider_id' => $samsara->id,
            'external_event_id' => 'panic-1',
            'occurred_at' => now()->subHour(),
            'headers_json' => ['x-samsara-signature' => 'v1=secreto'],
            'payload_json' => ['eventId' => 'panic-1', 'eventType' => 'AlertIncident', 'data' => ['conditions' => [['description' => 'Panic Button']]]],
        ]);
        RawEvent::factory()->create([
            'team_id' => $team->id,
            'event_source_id' => $feed->id,
            'provider_id' => $samsara->id,
            'external_event_id' => 'safety-1',
            'event_type_raw' => 'MaxSpeed',
            'occurred_at' => now()->subMinutes(10),
            'payload_json' => [
                'id' => 'safety-1',
                'behaviorLabels' => [['label' => 'MaxSpeed']],
                'downloadForwardVideoUrl' => 'https://s3.example/clip.mp4?X-Amz-Signature=abc',
                'media' => [['url' => 'https://s3.example/img.jpg?X-Amz-Signature=def']],
                'asset' => ['id' => '99', 'name' => 'T-1'],
            ],
        ]);
        // Otro tenant: nunca se exporta.
        RawEvent::factory()->create(['team_id' => $other->id, 'provider_id' => $samsara->id, 'external_event_id' => 'ajeno']);

        $this->artisan('samsara:export-fixtures', ['--team' => 'flota-real', '--output' => $this->output])->assertSuccessful();

        $panic = json_decode(File::get("{$this->output}/samsara-panic-events.json"), true);
        $safety = json_decode(File::get("{$this->output}/samsara-safety-events.json"), true);
        $all = File::get("{$this->output}/samsara-panic-events.json").File::get("{$this->output}/samsara-safety-events.json");

        $this->assertSame(['panic-1'], array_column($panic, 'samsara_event_id'));
        $this->assertSame('webhook', $panic[0]['kind']);
        $this->assertSame(['safety-1'], array_column($safety, 'samsara_event_id'));
        $this->assertSame('safety_event', $safety[0]['kind']);
        $this->assertStringNotContainsString('X-Amz-Signature', $all);
        $this->assertStringNotContainsString('secreto', $all);
        $this->assertStringNotContainsString('ajeno', $all);
        $this->assertFileDoesNotExist("{$this->output}/samsara-alert-events.json");
    }
}
