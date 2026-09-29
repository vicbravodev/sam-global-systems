<?php

namespace Tests\Feature\Domains\Automation;

use App\Domains\Automation\Actions\ExecuteAction;
use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Automation\Models\ActionTemplate;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\FakesHostResolution;
use Tests\TestCase;

/**
 * La acción call_webhook hace un POST a una URL que controla el tenant: sin
 * guard, era un SSRF hacia la red interna / metadata de la nube, siguiendo
 * redirecciones, con timeout libre y guardando la respuesta completa.
 */
class WebhookSsrfTest extends TestCase
{
    use AssertsSystemLog, FakesHostResolution, RefreshDatabase;

    private User $owner;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);

        $this->owner = User::factory()->create();
        $this->team = $this->owner->currentTeam;

        $this->fakeDns([
            'hooks.example.com' => ['93.184.216.34'],
            'rebind.example.com' => ['10.0.0.7'],
        ]);
    }

    private function execute(string $url, array $config = []): ActionExecution
    {
        $template = ActionTemplate::factory()->webhook($url)->create([
            'team_id' => $this->team->id,
            'config_json' => ['url' => $url, 'method' => 'POST', ...$config],
        ]);

        $execution = ActionExecution::factory()->create([
            'team_id' => $this->team->id,
            'action_type' => ActionType::CallWebhook,
            'status' => ActionExecutionStatus::Queued,
            'action_template_id' => $template->id,
            'payload_json' => ['incident' => 1],
        ]);

        return app(ExecuteAction::class)->execute($execution);
    }

    public function test_internal_targets_are_blocked_without_any_request(): void
    {
        Http::fake();

        foreach ([
            'https://169.254.169.254/latest/meta-data/',
            'https://127.0.0.1:8080/',
            'https://localhost/admin',
            'https://rebind.example.com/',
            'https://[::1]/',
        ] as $url) {
            $result = $this->execute($url);

            $this->assertSame(ActionExecutionStatus::Failed, $result->status, $url);
            $this->assertSame('La URL de destino no está permitida.', $result->error_message, $url);
        }

        Http::assertNothingSent();

        $codes = array_map(
            fn (array $entry) => $entry['context']['result']['unsafe_url_code'] ?? null,
            $this->systemLogEntries('automation.action.failed'),
        );
        $this->assertCount(5, $codes);
        foreach ($this->systemLogEntries('automation.action.failed') as $entry) {
            $this->assertSame('unsafe_url', $entry['context']['reason']);
            $this->assertSame('UnsafeOutboundUrlException', $entry['context']['result']['error_class']);
        }
        $this->assertContains('reserved_host', $codes);
        $this->assertContains('blocked_ip', $codes);
        $this->assertNotContains(null, $codes);

        $encoded = json_encode($this->systemLogEntries());
        foreach (['169.254.169.254', '127.0.0.1', 'localhost', 'rebind.example.com', '10.0.0.7', '::1'] as $host) {
            $this->assertStringNotContainsString($host, $encoded);
        }
        $this->assertNoSensitiveDataLogged();
    }

    public function test_public_target_is_called_without_redirects_and_with_capped_timeout(): void
    {
        $seen = [];

        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen = $options;

            return Http::response(['ok' => true], 200);
        });

        $result = $this->execute('https://hooks.example.com/hook', ['timeout_seconds' => 600]);

        $this->assertSame(ActionExecutionStatus::Completed, $result->status);
        $this->assertSame(['ok' => true], $result->response_json['body']);
        $this->assertFalse($seen['allow_redirects']);
        $this->assertLessThanOrEqual(10, $seen['timeout']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hooks.example.com/hook');
    }

    public function test_redirect_responses_are_not_followed(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/'])]);

        $result = $this->execute('https://hooks.example.com/hook');

        $this->assertSame(ActionExecutionStatus::Failed, $result->status);
        Http::assertSentCount(1);
    }

    public function test_only_a_truncated_body_is_stored(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response(str_repeat('A', 10_000), 200)]);

        $result = $this->execute('https://hooks.example.com/hook');

        $this->assertSame(ActionExecutionStatus::Completed, $result->status);
        $this->assertLessThanOrEqual(2_100, strlen((string) $result->response_json['body']));
    }

    public function test_action_template_with_internal_url_is_rejected_at_save_time(): void
    {
        $this->actingAs($this->owner)->postJson("/api/{$this->team->slug}/automation/templates", [
            'code' => 'ssrf',
            'name' => 'SSRF',
            'action_type' => 'call_webhook',
            'config_json' => ['url' => 'https://169.254.169.254/latest/meta-data/'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['config_json.url']);

        $this->actingAs($this->owner)->postJson("/api/{$this->team->slug}/automation/templates", [
            'code' => 'ok-hook',
            'name' => 'OK',
            'action_type' => 'call_webhook',
            'config_json' => ['url' => 'https://hooks.example.com/hook'],
        ])->assertCreated();
    }

    public function test_workflow_webhook_step_with_internal_url_is_rejected_at_save_time(): void
    {
        $this->actingAs($this->owner)->postJson(
            route('automation.workflows.store', ['current_team' => $this->team->slug]),
            [
                'code' => 'ssrf-flow',
                'name' => 'SSRF',
                'trigger_type' => 'incident_created',
                'status' => 'active',
                'steps_json' => [
                    ['action_type' => 'call_webhook', 'target_type' => 'url', 'target_reference' => 'https://rebind.example.com/'],
                ],
            ],
        )->assertUnprocessable()->assertJsonValidationErrors(['steps_json.0.target_reference']);
    }
}
