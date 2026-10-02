<?php

namespace Tests\Feature\Support;

use App\Models\User;
use App\Support\DeniedRequestLog;
use App\Support\SystemLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class DeniedRequestLogTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/_test/forbidden', fn () => abort(403))->name('test.forbidden');
        Route::middleware('web')->get('/_test/throttled', fn () => abort(429))->name('test.throttled');
        Route::middleware('web')->get('/_test/ok', fn () => 'ok')->name('test.ok');
    }

    public function test_forbidden_requests_are_logged_with_the_user_and_route_template(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/_test/forbidden')->assertForbidden();

        $ctx = $this->assertSystemLogged('http.request.denied');
        $this->assertSame('forbidden', $ctx['reason']);
        $this->assertSame('test.forbidden', $ctx['input']['route_name']);
        $this->assertSame($user->id, $ctx['input']['user_id']);
        $this->assertSame(403, $ctx['input']['status']);
    }

    public function test_a_flood_of_denials_is_capped_per_window_and_the_next_line_reports_the_suppressed(): void
    {
        $user = User::factory()->create();
        $max = DeniedRequestLog::MAX_PER_WINDOW;

        for ($i = 0; $i < $max + 3; $i++) {
            $this->actingAs($user)->get('/_test/forbidden')->assertForbidden();
        }

        $this->assertCount($max, $this->systemLogEntries('http.request.denied'));
        $this->assertArrayNotHasKey('calc', $this->systemLogEntries('http.request.denied')[0]['context']);

        // Otro actor tiene su propia cubeta: no lo calla la ráfaga del primero.
        $this->actingAs(User::factory()->create())->get('/_test/forbidden')->assertForbidden();
        $this->assertCount($max + 1, $this->systemLogEntries('http.request.denied'));

        $this->travel(DeniedRequestLog::WINDOW_SECONDS + 1)->seconds();
        $this->actingAs($user)->get('/_test/forbidden')->assertForbidden();

        $entries = $this->systemLogEntries('http.request.denied');
        $last = end($entries)['context'];
        $this->assertSame($user->id, $last['input']['user_id']);
        $this->assertSame([
            'suppressed_since_last' => 3,
            'max_per_window' => DeniedRequestLog::MAX_PER_WINDOW,
            'window_seconds' => DeniedRequestLog::WINDOW_SECONDS,
        ], $last['calc']);
    }

    public function test_throttled_requests_are_logged(): void
    {
        $this->get('/_test/throttled')->assertStatus(429);

        $this->assertSystemLogged('http.request.throttled', fn (array $c) => $c['reason'] === 'rate_limited');
    }

    public function test_unknown_webhook_endpoints_are_logged_without_the_real_path(): void
    {
        $this->postJson('/api/webhooks/00000000-0000-0000-0000-000000000000', [])->assertNotFound();

        $ctx = $this->assertSystemLogged('http.request.not_found');
        $this->assertSame('unknown_endpoint', $ctx['reason']);
        $this->assertStringNotContainsString('00000000-0000', (string) json_encode($ctx));
    }

    public function test_ordinary_404s_and_successes_are_not_logged_as_security_events(): void
    {
        $this->get('/_test/does-not-exist')->assertNotFound();
        $this->get('/_test/ok')->assertOk();

        $this->assertSystemNotLogged('http.request.not_found');
        $this->assertSystemNotLogged('http.request.denied');
    }

    public function test_a_failing_log_sink_never_changes_the_response(): void
    {
        SystemLog::listen(fn () => throw new RuntimeException('sink down'));

        $this->get('/_test/forbidden')->assertForbidden();
    }

    public function test_a_failing_context_callback_never_masks_the_reported_exception(): void
    {
        Route::middleware('web')->get('/_test/report', function (Request $request) {
            $routeResolver = $request->getRouteResolver();
            $userResolver = $request->getUserResolver();
            $request->setRouteResolver(fn () => throw new RuntimeException('route down'));
            $request->setUserResolver(fn () => throw new RuntimeException('session down'));

            try {
                report(new RuntimeException('original'));
            } finally {
                $request->setRouteResolver($routeResolver);
                $request->setUserResolver($userResolver);
            }

            return 'reported';
        });

        $this->get('/_test/report')->assertOk()->assertSee('reported');
    }
}
