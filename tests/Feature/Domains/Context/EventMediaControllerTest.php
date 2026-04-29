<?php

namespace Tests\Feature\Domains\Context;

use App\Domains\Context\Enums\MediaRequestStatus;
use App\Domains\Context\Enums\MediaRequestType;
use App\Domains\Context\Jobs\FetchDeferredEventMediaJob;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Context\Models\EventMediaRequest;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class EventMediaControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
    }

    public function test_index_returns_media_for_event(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);
        EventMediaContext::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
        ]);
        EventMediaContext::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
        ]);

        $this->actingAs($user);

        $response = $this->getJson("/api/{$team->slug}/events/{$event->id}/media");

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_index_blocks_access_across_teams(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $event = NormalizedEvent::factory()->create(['team_id' => $userA->currentTeam->id]);
        EventMediaContext::factory()->create([
            'team_id' => $userA->currentTeam->id,
            'normalized_event_id' => $event->id,
        ]);

        $this->actingAs($userB);

        $response = $this->getJson("/api/{$userA->currentTeam->slug}/events/{$event->id}/media");

        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_request_creates_pending_event_media_request(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user);

        $response = $this->postJson(
            "/api/{$team->slug}/events/{$event->id}/media/request",
            ['request_type' => MediaRequestType::FetchVideoClip->value],
        );

        $response->assertStatus(202);

        $this->assertSame(
            1,
            EventMediaRequest::withoutGlobalScopes()
                ->where('normalized_event_id', $event->id)
                ->where('status', MediaRequestStatus::Pending->value)
                ->count(),
        );

        Bus::assertDispatched(FetchDeferredEventMediaJob::class);
    }

    public function test_request_validates_request_type(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user);

        $response = $this->postJson(
            "/api/{$team->slug}/events/{$event->id}/media/request",
            ['request_type' => 'fetch_unknown'],
        );

        $response->assertStatus(422);
    }
}
