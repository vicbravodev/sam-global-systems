<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Integrations\Adapters\SamsaraAdapter;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SamsaraAdapterSafetyEventsTest extends TestCase
{
    use RefreshDatabase;

    private function makeIntegration(?string $token = 'sk-test-token'): TenantIntegration
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->samsara()->create();

        $integration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'name' => 'Samsara Fleet',
            'status' => 'active',
            'auth_type' => 'api_key',
            'credentials_encrypted' => '',
        ]);

        if ($token !== null) {
            IntegrationCredential::create([
                'tenant_integration_id' => $integration->id,
                'key' => 'api_token',
                'value_encrypted' => $token,
            ]);
        }

        return $integration->load('provider');
    }

    public function test_first_poll_sends_start_time_and_returns_events_with_cursor(): void
    {
        Http::fake([
            'api.samsara.com/safety-events/stream*' => Http::response([
                'data' => [
                    [
                        'id' => 'evt-1',
                        'behaviorLabels' => [['label' => 'Crash']],
                        'eventState' => 'needsReview',
                        'location' => ['latitude' => 10.0, 'longitude' => 20.0],
                    ],
                ],
                'pagination' => ['endCursor' => 'cursor-abc', 'hasNextPage' => false],
            ], 200),
        ]);

        $result = app(SamsaraAdapter::class)->fetchSafetyEvents(
            $this->makeIntegration(),
            null,
            now()->subDay(),
        );

        $this->assertCount(1, $result['events']);
        $this->assertSame('evt-1', $result['events'][0]['id']);
        $this->assertSame('cursor-abc', $result['cursor']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'startTime=')
                && ! str_contains($request->url(), 'after=');
        });
    }

    public function test_poll_with_cursor_resumes_via_after_param(): void
    {
        Http::fake([
            'api.samsara.com/safety-events/stream*' => Http::response([
                'data' => [],
                'pagination' => ['endCursor' => 'cursor-next', 'hasNextPage' => false],
            ], 200),
        ]);

        $result = app(SamsaraAdapter::class)->fetchSafetyEvents($this->makeIntegration(), 'cursor-prev');

        $this->assertSame([], $result['events']);
        $this->assertSame('cursor-next', $result['cursor']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'after=cursor-prev')
                && ! str_contains($request->url(), 'startTime=');
        });
    }

    public function test_paginates_until_last_page(): void
    {
        Http::fake([
            'api.samsara.com/safety-events/stream*' => Http::sequence()
                ->push([
                    'data' => [['id' => 'evt-1', 'eventState' => 'needsReview']],
                    'pagination' => ['endCursor' => 'page-2', 'hasNextPage' => true],
                ])
                ->push([
                    'data' => [['id' => 'evt-2', 'eventState' => 'needsReview']],
                    'pagination' => ['endCursor' => 'page-3', 'hasNextPage' => false],
                ]),
        ]);

        $result = app(SamsaraAdapter::class)->fetchSafetyEvents($this->makeIntegration());

        $this->assertCount(2, $result['events']);
        $this->assertSame('page-3', $result['cursor']);
        Http::assertSentCount(2);
    }

    public function test_returns_empty_and_echoes_cursor_without_token(): void
    {
        $result = app(SamsaraAdapter::class)->fetchSafetyEvents($this->makeIntegration(token: null), 'cursor-kept');

        $this->assertSame(['events' => [], 'cursor' => 'cursor-kept'], $result);
    }

    public function test_keeps_cursor_on_http_failure(): void
    {
        Http::fake([
            'api.samsara.com/safety-events/stream*' => Http::response([], 500),
        ]);

        $result = app(SamsaraAdapter::class)->fetchSafetyEvents($this->makeIntegration(), 'cursor-prev');

        $this->assertSame([], $result['events']);
        $this->assertSame('cursor-prev', $result['cursor']);
    }
}
