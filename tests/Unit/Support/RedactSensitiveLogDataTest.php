<?php

namespace Tests\Unit\Support;

use App\Support\RedactSensitiveLogData;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RedactSensitiveLogDataTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sensitiveStrings(): array
    {
        return [
            'e164 phone' => ['llamada a +525512345678 falló', 'llamada a [phone] falló'],
            'spaced phone' => ['tel 55 1234 5678 ok', 'tel [phone] ok'],
            'email' => ['user Ana.Perez+x@empresa.com.mx rechazado', 'user [email] rechazado'],
            'signed url' => ['GET https://s3.amazonaws.com/b/k.jpg?X-Amz-Signature=abc&X-Amz-Credential=d failed', 'GET https://s3.amazonaws.com/b/k.jpg?[redacted] failed'],
            'bearer' => ['Authorization: Bearer eyJhbGciOi.abc-def_ghi', 'Authorization: Bearer [redacted]'],
        ];
    }

    #[DataProvider('sensitiveStrings')]
    public function test_sanitize_masks_sensitive_patterns(string $input, string $expected): void
    {
        $this->assertSame($expected, RedactSensitiveLogData::sanitize($input));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function harmlessStrings(): array
    {
        return [
            'iso timestamp' => ['2026-09-28T10:00:00Z'],
            'date' => ['desde 2026-09-28 hasta 2026-10-01'],
            'decimal' => ['riesgo 0.123456789'],
            'ulid' => ['01k6b7yq3m9x2c4d5e6f7g8h9j'],
            'uuid' => ['0e8f1c2a-3b4d-4e5f-8a9b-123456789012'],
            'short number' => ['intento 3 de 5, 1200 ms'],
            'url without query' => ['https://api.samsara.com/fleet/vehicles/stats'],
        ];
    }

    #[DataProvider('harmlessStrings')]
    public function test_sanitize_keeps_harmless_values(string $input): void
    {
        $this->assertSame($input, RedactSensitiveLogData::sanitize($input));
    }

    public function test_redact_masks_sensitive_keys_recursively_but_keeps_technical_keys(): void
    {
        $out = RedactSensitiveLogData::redact([
            'from' => '+525512345678',
            'token' => 'ABC123',
            'phone_number' => '5512345678',
            'recipient' => ['email' => 'a@b.co', 'name' => 'Ana', 'user_id' => 9],
            'raw_payload' => ['x' => 1],
            'signature' => 'deadbeef',
            'raw_event_id' => 5,
            'token_id' => 7,
            'event_name' => 'IncidentCreated',
            'has_signature' => true,
            'signature_mode' => 'raw_header',
            'job' => 'App\\Jobs\\X',
            'count' => 12345678901,
        ]);

        $this->assertSame('[phone]', $out['from']);
        $this->assertSame('[redacted]', $out['token']);
        $this->assertSame('[redacted]', $out['phone_number']);
        $this->assertSame(['email' => '[redacted]', 'name' => '[redacted]', 'user_id' => 9], $out['recipient']);
        $this->assertSame('[redacted]', $out['raw_payload']);
        $this->assertSame('[redacted]', $out['signature']);
        $this->assertSame(5, $out['raw_event_id']);
        $this->assertSame(7, $out['token_id']);
        $this->assertSame('IncidentCreated', $out['event_name']);
        $this->assertTrue($out['has_signature']);
        $this->assertSame('raw_header', $out['signature_mode']);
        $this->assertSame('App\\Jobs\\X', $out['job']);
        $this->assertSame(12345678901, $out['count']);
    }

    public function test_redact_describes_throwables_safely(): void
    {
        $out = RedactSensitiveLogData::redact(['exception' => new RuntimeException('no se pudo llamar a +525512345678')]);

        $this->assertSame(RuntimeException::class, $out['exception']['class']);
        $this->assertSame('no se pudo llamar a [phone]', $out['exception']['message']);
        $this->assertArrayHasKey('trace', $out['exception']);
    }

    public function test_findings_lists_the_paths_that_would_be_redacted(): void
    {
        $this->assertSame(
            ['from', 'recipient.email'],
            RedactSensitiveLogData::findings(['from' => '+525512345678', 'recipient' => ['email' => 'a@b.co', 'user_id' => 1], 'ok' => 'x']),
        );
        $this->assertSame([], RedactSensitiveLogData::findings(['raw_event_id' => 1, 'reason' => 'skip_category']));
    }

    public function test_api_keys_are_redacted_even_though_key_is_a_technical_suffix(): void
    {
        $this->assertSame(
            ['api_key' => '[redacted]', 'apiKey' => '[redacted]', 'event_key' => 'evt:1'],
            RedactSensitiveLogData::redact(['api_key' => 'sk-1', 'apiKey' => 'sk-2', 'event_key' => 'evt:1']),
        );
    }

    public function test_processor_redacts_message_context_and_extra(): void
    {
        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: Level::Info,
            message: 'aviso a ana@x.com',
            context: ['phone' => '5512345678', 'raw_event_id' => 1],
            extra: ['trace_id' => '01k6b7yq3m9x2c4d5e6f7g8h9j', 'email' => 'b@y.com'],
        );

        $out = (new RedactSensitiveLogData)($record);

        $this->assertSame('aviso a [email]', $out->message);
        $this->assertSame(['phone' => '[redacted]', 'raw_event_id' => 1], $out->context);
        $this->assertSame(['trace_id' => '01k6b7yq3m9x2c4d5e6f7g8h9j', 'email' => '[redacted]'], $out->extra);
    }
}
