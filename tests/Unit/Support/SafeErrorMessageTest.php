<?php

namespace Tests\Unit\Support;

use App\Domains\Automation\Support\ActionFailure;
use App\Domains\Integrations\Exceptions\ProviderUnavailable;
use App\Support\Http\UnsafeOutboundUrlException;
use App\Support\SafeErrorMessage;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use Twilio\Exceptions\RestException;

class SafeErrorMessageTest extends TestCase
{
    private const string SENSITIVE = 'POST https://hooks.example.com/services/T0/B0/xyzSecretPath?token=abc123 '
        .'failed for ana@cliente.mx (+52 55 1234 5678) Authorization: Bearer sk-live-abcdef';

    public function test_a_foreign_exception_keeps_only_its_short_class(): void
    {
        $text = SafeErrorMessage::from(new RuntimeException(self::SENSITIVE));

        $this->assertSame('RuntimeException', $text);
    }

    public function test_a_foreign_exception_keeps_its_numeric_code(): void
    {
        $this->assertSame('InvalidArgumentException (código 42)', SafeErrorMessage::from(new InvalidArgumentException(self::SENSITIVE, 42)));
    }

    public function test_an_allowlisted_exception_keeps_its_message_redacted(): void
    {
        $text = SafeErrorMessage::from(new ActionFailure('no_recipients', self::SENSITIVE));

        $this->assertStringContainsString('POST https://hooks.example.com/[redacted]', $text);
        $this->assertStringContainsString('failed for [email] ([phone])', $text);
        $this->assertStringContainsString('Bearer [redacted]', $text);
        $this->assertStringNotContainsString('xyzSecretPath', $text);
        $this->assertStringNotContainsString('abc123', $text);
        $this->assertStringNotContainsString('ana@cliente.mx', $text);
        $this->assertStringNotContainsString('1234 5678', $text);
        $this->assertStringNotContainsString('sk-live-abcdef', $text);
    }

    public function test_an_allowlisted_query_string_is_redacted_even_on_an_allowed_host(): void
    {
        $text = SafeErrorMessage::from(new ProviderUnavailable('GET https://api.samsara.com/fleet/vehicles?access_token=leaky failed'));

        $this->assertSame('GET https://api.samsara.com/fleet/vehicles?[redacted] failed', $text);
    }

    public function test_an_own_message_with_safe_text_is_kept_verbatim(): void
    {
        $this->assertSame('La URL de destino no está permitida.', SafeErrorMessage::from(new UnsafeOutboundUrlException('private_ip')));
        $this->assertSame('Webhook returned status 500', SafeErrorMessage::from(new ActionFailure('webhook_http_error', 'Webhook returned status 500')));
    }

    public function test_subclasses_of_an_allowlisted_type_are_allowlisted(): void
    {
        $this->assertTrue(SafeErrorMessage::hasSafeMessage(new ProviderUnavailable('Samsara returned HTTP 503.')));
        $this->assertFalse(SafeErrorMessage::hasSafeMessage(new RuntimeException('x')));
    }

    public function test_an_allowlisted_exception_without_message_falls_back_to_its_class(): void
    {
        $this->assertSame('ActionFailure', SafeErrorMessage::from(new ActionFailure('x', '   ')));
    }

    public function test_truncates_to_the_default_and_to_a_custom_limit(): void
    {
        $long = new ActionFailure('x', str_repeat('a', 2000));

        $this->assertSame(SafeErrorMessage::DEFAULT_LIMIT, mb_strlen(SafeErrorMessage::from($long)));
        $this->assertStringEndsWith('…', SafeErrorMessage::from($long));
        $this->assertSame(50, mb_strlen(SafeErrorMessage::from($long, 50)));
    }

    public function test_http_request_exceptions_keep_only_the_status(): void
    {
        $e = new RequestException(new Response(new Psr7Response(503, [], '{"error":"down","driver":"ana@cliente.mx"}')));

        $this->assertSame('RequestException (HTTP 503)', SafeErrorMessage::from($e));
    }

    public function test_connection_exceptions_keep_only_the_curl_code(): void
    {
        $e = new ConnectionException('cURL error 28: Operation timed out after 8001 milliseconds for https://hooks.example.com/secret-path?token=abc123');

        $this->assertSame('ConnectionException (cURL 28)', SafeErrorMessage::from($e));
    }

    public function test_query_exceptions_keep_only_the_sqlstate(): void
    {
        $pdo = new PDOException('SQLSTATE[23505]: Unique violation');
        $pdo->errorInfo = ['23505', 7, 'duplicate'];
        $e = new QueryException('pgsql', 'insert into users (email) values (?)', ['ana@cliente.mx'], $pdo);

        $this->assertSame('QueryException (SQLSTATE 23505)', SafeErrorMessage::from($e));
    }

    public function test_twilio_rest_exceptions_keep_the_twilio_code_and_status(): void
    {
        $e = new RestException("Unable to create record: The 'To' number +5215555550123 is not a valid phone number.", 21211, 400);

        $this->assertSame('RestException (código 21211, HTTP 400)', SafeErrorMessage::from($e));
    }

    public function test_every_allowlisted_type_is_a_throwable_class(): void
    {
        foreach (SafeErrorMessage::SAFE_MESSAGE_TYPES as $type) {
            $this->assertTrue(class_exists($type), $type);
            $this->assertTrue(is_subclass_of($type, Throwable::class), $type);
        }
    }
}
