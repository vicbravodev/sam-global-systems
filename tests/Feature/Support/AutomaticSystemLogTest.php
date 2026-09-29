<?php

namespace Tests\Feature\Support;

use App\Models\User;
use App\Support\AutomaticSystemLog;
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

    public function test_webhook_calls_never_log_the_secret_path(): void
    {
        Http::fake([
            'hooks.slack.com/*' => Http::response('ok', 200),
            'example.org/*' => Http::response('ok', 200),
        ]);

        Http::post('https://hooks.slack.com/services/T0001/B0002/XyZsecret123', ['text' => 'hola']);
        Http::post('https://example.org/hooks/tenant-7/s3cr3tSegment', ['a' => 1]);

        $slack = $this->assertSystemLogged('http.client.request.completed', fn (array $c) => $c['input']['host'] === 'hooks.slack.com');
        $this->assertSame('slack', $slack['input']['provider']);
        $this->assertArrayNotHasKey('path', $slack['input']);
        $this->assertSame(substr(hash('sha256', '/services/T0001/B0002/XyZsecret123'), 0, 12), $slack['input']['path_hash']);

        $custom = $this->assertSystemLogged('http.client.request.completed', fn (array $c) => $c['input']['host'] === 'example.org');
        $this->assertSame('other', $custom['input']['provider']);
        $this->assertArrayNotHasKey('path', $custom['input']);

        $json = json_encode($this->systemLogEntries('http.client.request.completed'));
        $this->assertStringNotContainsString('XyZsecret123', (string) $json);
        $this->assertStringNotContainsString('s3cr3tSegment', (string) $json);
    }

    public function test_webhook_connection_failures_never_log_the_secret_in_the_error(): void
    {
        Http::fake(fn () => Http::failedConnection('cURL error 28: Operation timed out for https://hooks.slack.com/services/T0001/B0002/XyZsecret123'));

        try {
            Http::post('https://hooks.slack.com/services/T0001/B0002/XyZsecret123', ['text' => 'hola']);
        } catch (ConnectionException) {
        }

        $failed = $this->assertSystemLogged('http.client.request.failed');
        $this->assertArrayNotHasKey('path', $failed['input']);
        $this->assertStringNotContainsString('XyZsecret123', (string) json_encode($failed));
    }

    public function test_http_inside_a_telematics_job_goes_to_the_telematics_channel_at_debug(): void
    {
        Http::fake(['api.samsara.com/*' => Http::response(['data' => []], 200)]);

        $this->runOnTelematicsQueue();

        $entry = collect($this->systemLogEntries('http.client.request.completed'))->firstWhere('context.input.provider', 'samsara');
        $this->assertNotNull($entry);
        $this->assertSame('debug', $entry['level']);
        $this->assertSame('telematics', $entry['channel']);

        $finished = collect($this->systemLogEntries('queue.job.finished'))->firstWhere('context.input.job', AutomaticSystemLogTelematicsHttpJob::class);
        $this->assertSame('debug', $finished['level']);
        $this->assertSame('telematics', $finished['channel']);

        // Fuera del job, el flag está limpio: la siguiente llamada vuelve al canal por defecto a info.
        Http::get('https://api.samsara.com/fleet/vehicles');
        $after = $this->systemLogEntries('http.client.request.completed');
        $this->assertSame('info', end($after)['level']);
        $this->assertNull(end($after)['channel']);
    }

    public function test_failures_inside_a_telematics_job_keep_their_level_on_the_telematics_channel(): void
    {
        Http::fake(['api.samsara.com/*' => Http::response('down', 503)]);

        $this->runOnTelematicsQueue();

        $entry = $this->systemLogEntries('http.client.request.completed')[0];
        $this->assertSame('warning', $entry['level']);
        $this->assertSame('telematics', $entry['channel']);
    }

    /**
     * La cola `sync` reporta siempre `sync` como nombre de cola: se pasa por
     * la cola `database` y un worker real para que el job vea `telematics`.
     */
    private function runOnTelematicsQueue(): void
    {
        config(['queue.default' => 'database']);

        AutomaticSystemLogTelematicsHttpJob::dispatch()->onQueue('telematics');

        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'telematics', '--once' => true, '--tries' => 1])->assertSuccessful();
    }

    public function test_a_retried_attempt_logs_its_duration_and_frees_the_start_time(): void
    {
        config(['queue.default' => 'database']);

        AutomaticSystemLogFailingJob::dispatch();

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 3])->assertSuccessful();

        $attempt = $this->assertSystemLogged('queue.job.attempt_failed', fn (array $c) => $c['input']['job'] === AutomaticSystemLogFailingJob::class);
        $this->assertIsInt($attempt['duration_ms']);
        $this->assertSystemNotLogged('queue.job.failed');
        $this->assertSame([], (new \ReflectionProperty(AutomaticSystemLog::class, 'startedAt'))->getValue());
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
        $this->assertSame(substr(hash_hmac('sha256', 'ana@empresa.com', (string) config('app.key')), 0, 12), $failed['input']['login_fingerprint']);

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

class AutomaticSystemLogTelematicsHttpJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(): void
    {
        Http::get('https://api.samsara.com/fleet/vehicles/stats/feed');
    }
}
