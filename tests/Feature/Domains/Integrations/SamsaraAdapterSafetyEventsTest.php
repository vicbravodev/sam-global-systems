<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Integrations\Adapters\SamsaraAdapter;
use App\Domains\Integrations\Exceptions\ProviderCursorRejectedException;
use App\Domains\Integrations\Exceptions\ProviderRequestFailedException;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\FakesSamsaraSafetyStream;
use Tests\TestCase;

class SamsaraAdapterSafetyEventsTest extends TestCase
{
    use FakesSamsaraSafetyStream;
    use RefreshDatabase;

    private const string PINNED_START = '2026-09-26T08:00:00+00:00';

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

    public function test_first_poll_sends_start_time_and_returns_events_cursor_and_pinned_start_time(): void
    {
        $this->fakeSafetyStream([
            ['data' => [['id' => 'evt-1', 'eventState' => 'needsReview']], 'endCursor' => 'cursor-abc'],
        ]);

        $result = app(SamsaraAdapter::class)->fetchSafetyEvents($this->makeIntegration(), null, self::PINNED_START);

        $this->assertCount(1, $result['events']);
        $this->assertSame('evt-1', $result['events'][0]['id']);
        $this->assertSame('cursor-abc', $result['cursor']);
        $this->assertSame(self::PINNED_START, $result['start_time']);
        $this->assertFalse($result['has_more']);

        $this->assertSame([['startTime' => self::PINNED_START]], $this->sentSafetyStreamQueries());
    }

    public function test_date_start_time_is_formatted_once_and_returned_for_pinning(): void
    {
        $this->fakeSafetyStream([['data' => []]]);

        $result = app(SamsaraAdapter::class)->fetchSafetyEvents(
            $this->makeIntegration(),
            null,
            new \DateTimeImmutable(self::PINNED_START),
        );

        $this->assertSame(self::PINNED_START, $result['start_time']);
        $this->assertSame(self::PINNED_START, $this->sentSafetyStreamQueries()[0]['startTime']);
    }

    public function test_resume_sends_after_and_the_pinned_start_time_together(): void
    {
        $this->fakeSafetyStream(
            [['data' => [], 'endCursor' => 'cursor-next']],
            knownCursors: ['cursor-prev' => self::PINNED_START],
        );

        $result = app(SamsaraAdapter::class)->fetchSafetyEvents($this->makeIntegration(), 'cursor-prev', self::PINNED_START);

        $this->assertSame('cursor-next', $result['cursor']);
        $this->assertSame(self::PINNED_START, $result['start_time']);
        $this->assertSame(
            [['startTime' => self::PINNED_START, 'after' => 'cursor-prev']],
            $this->sentSafetyStreamQueries(),
        );
    }

    public function test_resume_with_a_different_start_time_is_rejected_as_a_cursor_problem(): void
    {
        $this->fakeSafetyStream([], knownCursors: ['cursor-prev' => self::PINNED_START]);

        $this->expectException(ProviderCursorRejectedException::class);
        $this->expectExceptionMessage('Parameters differ');

        app(SamsaraAdapter::class)->fetchSafetyEvents($this->makeIntegration(), 'cursor-prev', '2026-09-26T08:00:01+00:00');
    }

    public function test_expired_cursor_raises_cursor_rejected(): void
    {
        $this->fakeSafetyStream([]);

        try {
            app(SamsaraAdapter::class)->fetchSafetyEvents($this->makeIntegration(), 'cursor-from-august', self::PINNED_START);
            $this->fail('An expired cursor must not be reported as an empty poll.');
        } catch (ProviderCursorRejectedException $e) {
            $this->assertSame(400, $e->status);
            $this->assertSame('invalid or expired pagination cursor', $e->providerMessage);
        }
    }

    public function test_paginates_every_page_with_the_same_start_time(): void
    {
        $this->fakeSafetyStream([
            ['data' => [['id' => 'evt-1', 'eventState' => 'needsReview']], 'hasNextPage' => true, 'endCursor' => 'page-2'],
            ['data' => [['id' => 'evt-2', 'eventState' => 'needsReview']], 'hasNextPage' => true, 'endCursor' => 'page-3'],
            ['data' => [['id' => 'evt-3', 'eventState' => 'dismissed']], 'hasNextPage' => false, 'endCursor' => 'page-4'],
        ]);

        $result = app(SamsaraAdapter::class)->fetchSafetyEvents($this->makeIntegration(), null, self::PINNED_START);

        $this->assertSame(['evt-1', 'evt-2', 'evt-3'], array_column($result['events'], 'id'));
        $this->assertSame('page-4', $result['cursor']);
        $this->assertFalse($result['has_more']);
        $this->assertSame([
            ['startTime' => self::PINNED_START],
            ['startTime' => self::PINNED_START, 'after' => 'page-2'],
            ['startTime' => self::PINNED_START, 'after' => 'page-3'],
        ], $this->sentSafetyStreamQueries());
    }

    public function test_page_cap_stops_the_run_and_reports_more_pending(): void
    {
        $this->fakeSafetyStream(array_fill(0, 60, ['data' => [], 'hasNextPage' => true]));

        $result = app(SamsaraAdapter::class)->fetchSafetyEvents($this->makeIntegration(), null, self::PINNED_START);

        $this->assertTrue($result['has_more']);
        $this->assertSame('stream-cursor-50', $result['cursor']);
        Http::assertSentCount(50);
    }

    public function test_returns_empty_and_echoes_cursor_without_token(): void
    {
        Http::fake();

        $result = app(SamsaraAdapter::class)->fetchSafetyEvents($this->makeIntegration(token: null), 'cursor-kept', self::PINNED_START);

        $this->assertSame([], $result['events']);
        $this->assertSame('cursor-kept', $result['cursor']);
        $this->assertSame(self::PINNED_START, $result['start_time']);
        Http::assertNothingSent();
    }

    public function test_server_error_throws_instead_of_returning_an_empty_poll(): void
    {
        $this->fakeSafetyStream([['status' => 500, 'body' => ['message' => 'Failed to execute GraphQL query.', 'requestId' => 'req-9']]]);

        try {
            app(SamsaraAdapter::class)->fetchSafetyEvents($this->makeIntegration(), null, self::PINNED_START);
            $this->fail('A 500 must not be reported as an empty poll.');
        } catch (ProviderRequestFailedException $e) {
            $this->assertNotInstanceOf(ProviderCursorRejectedException::class, $e);
            $this->assertSame(500, $e->status);
            $this->assertStringContainsString('HTTP 500', $e->getMessage());
            $this->assertStringContainsString('req-9', $e->getMessage());
        }
    }

    public function test_failure_on_a_later_page_throws_and_discards_the_partial_run(): void
    {
        $this->fakeSafetyStream([
            ['data' => [['id' => 'evt-1', 'eventState' => 'needsReview']], 'hasNextPage' => true],
            ['status' => 503],
        ]);

        $this->expectException(ProviderRequestFailedException::class);

        app(SamsaraAdapter::class)->fetchSafetyEvents($this->makeIntegration(), null, self::PINNED_START);
    }

    public function test_rate_limit_exposes_retry_after(): void
    {
        $this->fakeSafetyStream([['status' => 429, 'body' => ['message' => 'Exceeded rate limit.'], 'headers' => ['Retry-After' => '7']]]);

        try {
            app(SamsaraAdapter::class)->fetchSafetyEvents($this->makeIntegration(), null, self::PINNED_START);
            $this->fail('A 429 must surface as an exception.');
        } catch (ProviderRequestFailedException $e) {
            $this->assertTrue($e->isRateLimited());
            $this->assertSame(7, $e->retryAfterSeconds);
        }
    }

    public function test_sync_fails_loudly_when_the_vehicle_listing_is_rejected(): void
    {
        Http::fake([
            'api.samsara.com/fleet/vehicles*' => Http::response(['message' => 'Invalid token.', 'requestId' => Str::random(8)], 401),
            'api.samsara.com/fleet/drivers*' => Http::response(['data' => [], 'pagination' => ['endCursor' => '', 'hasNextPage' => false]]),
        ]);

        $this->expectException(ProviderRequestFailedException::class);
        $this->expectExceptionMessage('GET /fleet/vehicles respondió HTTP 401');

        app(SamsaraAdapter::class)->sync($this->makeIntegration(), 'full');
    }
}
