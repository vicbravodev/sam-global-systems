<?php

namespace Tests\Feature\Domains\AI\Clef;

use App\Infrastructure\AI\Clef\ClefClient;
use App\Infrastructure\AI\Clef\ClefRequestFailedException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Domains\AI\Clef\Concerns\BuildsClefFixtures;
use Tests\TestCase;

class ClefClientTest extends TestCase
{
    use BuildsClefFixtures;

    /** @var array<string, array<string, mixed>> */
    private array $questions = [
        'classification' => ['type' => 'choice', 'instructions' => 'x', 'criteria' => ['real_event' => 'a', 'false_positive' => 'b', 'noise' => 'c', 'duplicate' => 'd', 'unclear' => 'e']],
        'severity' => ['type' => 'score', 'instructions' => 'x', 'criteria' => ['0', '1', '2', '3', '4']],
        'needs_human_now' => ['type' => 'noul', 'instructions' => 'x'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.cloudflare.account_id' => 'acc-123', 'services.cloudflare.auth_token' => 'tok-secret']);
    }

    public function test_sends_model_state_questions_and_images_and_parses_answers(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response($this->clefResponse('noise'))]);

        $response = app(ClefClient::class)->run('clef-flash', ['a' => 1], $this->questions, [['content_type' => 'image/jpeg', 'base64' => 'AAA=']]);

        $this->assertSame('noise', $response->answers['classification']['choice']);
        $this->assertSame(2900, $response->inputTokens);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.cloudflare.com/client/v4/accounts/acc-123/ai/run/@cf/cloudflare/clef-flash'
                && $request->hasHeader('Authorization', 'Bearer tok-secret')
                && $request['model'] === 'clef-flash'
                && $request['state'] === ['a' => 1]
                && array_keys($request['questions']) === ['classification', 'severity', 'needs_human_now']
                && $request['images'] === [['content_type' => 'image/jpeg', 'base64' => 'AAA=']];
        });
    }

    public function test_omits_images_key_when_there_are_none(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response($this->clefResponse('noise'))]);

        app(ClefClient::class)->run('clef', ['a' => 1], $this->questions);

        Http::assertSent(fn (Request $request): bool => ! array_key_exists('images', $request->data()));
    }

    public function test_server_error_is_retryable(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false], 503)]);

        try {
            app(ClefClient::class)->run('clef', ['a' => 1], $this->questions);
            $this->fail('Expected exception');
        } catch (ClefRequestFailedException $e) {
            $this->assertSame('http_503', $e->reason);
            $this->assertTrue($e->retryable);
        }
    }

    public function test_rate_limit_is_retryable_and_client_error_is_not(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::sequence()->push([], 429)->push([], 400)]);
        $client = app(ClefClient::class);

        foreach ([['http_429', true], ['http_400', false]] as [$reason, $retryable]) {
            try {
                $client->run('clef', ['a' => 1], $this->questions);
                $this->fail('Expected exception');
            } catch (ClefRequestFailedException $e) {
                $this->assertSame($reason, $e->reason);
                $this->assertSame($retryable, $e->retryable);
            }
        }
    }

    public function test_missing_answer_is_malformed(): void
    {
        $body = $this->clefResponse('noise');
        unset($body['result']['answers']['severity']);
        Http::fake(['api.cloudflare.com/*' => Http::response($body)]);

        $this->expectExceptionObject(new ClefRequestFailedException('malformed_response', false));

        app(ClefClient::class)->run('clef', ['a' => 1], $this->questions);
    }

    public function test_choice_outside_requested_options_is_malformed(): void
    {
        $body = $this->clefResponse('noise');
        $body['result']['answers']['classification']['choice'] = 'panic';
        Http::fake(['api.cloudflare.com/*' => Http::response($body)]);

        $this->expectExceptionObject(new ClefRequestFailedException('malformed_response', false));

        app(ClefClient::class)->run('clef', ['a' => 1], $this->questions);
    }
}
