<?php

namespace Tests\Feature\Support;

use App\Models\User;
use App\Support\SystemLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class AutomaticSystemLogTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    public function test_a_finished_job_is_logged_with_duration_and_attempt(): void
    {
        AutomaticSystemLogOkJob::dispatch();

        $ctx = $this->assertSystemLogged('queue.job.finished', fn (array $c) => $c['input']['job'] === AutomaticSystemLogOkJob::class);

        $this->assertSame(1, $ctx['input']['attempt']);
        $this->assertArrayHasKey('duration_ms', $ctx);
    }

    public function test_a_failing_job_logs_the_attempt_and_the_failure_safely(): void
    {
        try {
            AutomaticSystemLogFailingJob::dispatch();
        } catch (RuntimeException) {
        }

        $this->assertSystemLogged('queue.job.attempt_failed', fn (array $c) => $c['error']['message'] === 'llamada a [phone] falló');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_outgoing_http_is_logged_without_query_headers_or_body(): void
    {
        Http::fake([
            'api.samsara.com/*' => Http::response(['data' => []], 200),
            'api.twilio.com/*' => Http::response('nope', 500),
        ]);

        Http::withToken('secret-token')->get('https://api.samsara.com/fleet/vehicles/stats?types=gps&after=abc');
        Http::post('https://api.twilio.com/2010-04-01/Calls.json', ['To' => '+525512345678']);

        $ok = $this->assertSystemLogged('http.client.request.completed', fn (array $c) => $c['input']['provider'] === 'samsara');
        $this->assertSame(['provider' => 'samsara', 'method' => 'GET', 'host' => 'api.samsara.com', 'path' => '/fleet/vehicles/stats', 'status' => 200], $ok['input']);
        $this->assertSame('ok', $ok['outcome']);

        $this->assertSystemLogged('http.client.request.completed', fn (array $c) => $c['input']['provider'] === 'twilio' && $c['outcome'] === 'degraded' && $c['reason'] === 'http_error');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_connection_failures_are_logged(): void
    {
        Http::fake(fn () => Http::failedConnection('cURL error 7: Failed to connect to 10.0.0.1'));

        try {
            Http::get('https://api.openai.com/v1/responses');
        } catch (ConnectionException) {
        }

        $this->assertSystemLogged('http.client.request.failed', fn (array $c) => $c['reason'] === 'connection_failed' && $c['input']['provider'] === 'openai');
    }

    public function test_a_broken_log_sink_never_breaks_http_or_jobs(): void
    {
        SystemLog::listen(fn () => throw new RuntimeException('sink down'));
        Http::fake(['api.samsara.com/*' => Http::response(['data' => []], 200)]);

        $response = Http::get('https://api.samsara.com/fleet/vehicles');

        $this->assertSame(200, $response->status());

        AutomaticSystemLogOkJob::dispatch();
        $this->addToAssertionCount(1);
    }

    public function test_auth_events_never_log_the_email(): void
    {
        $user = User::factory()->create(['email' => 'ana@empresa.com']);

        event(new Failed('web', null, ['email' => 'Ana@Empresa.com', 'password' => 'x']));
        event(new Lockout(Request::create('/login', 'POST', ['email' => 'ana@empresa.com'])));
        event(new Login('web', $user, false));

        $failed = $this->assertSystemLogged('auth.login.failed');
        $this->assertSame('invalid_credentials', $failed['reason']);
        $this->assertSame(substr(hash('sha256', 'ana@empresa.com'), 0, 12), $failed['input']['login_fingerprint']);

        $this->assertSystemLogged('auth.login.locked_out', fn (array $c) => $c['input']['login_fingerprint'] === $failed['input']['login_fingerprint']);
        $this->assertSystemLogged('auth.login.succeeded', fn (array $c) => $c['input']['user_id'] === $user->id);
        $this->assertNoSensitiveDataLogged();
    }
}

class AutomaticSystemLogOkJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(): void {}
}

class AutomaticSystemLogFailingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(): void
    {
        throw new RuntimeException('llamada a +525512345678 falló');
    }
}
