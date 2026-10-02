<?php

namespace Tests\Unit\Support;

use App\Support\RedactSensitiveLogData;
use DateTimeImmutable;
use Illuminate\Contracts\Support\Arrayable;
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
            'basic header' => ['Authorization: Basic QUMxMjM6c2VjcmV0', 'Authorization: Basic [redacted]'],
            'basic credential in prose' => ['request with Basic QUMxMjM6c2VjcmV0 failed', 'request with Basic [redacted] failed'],
            'lowercase basic header' => ['authorization=basic dXNlcjpwYXNz', 'authorization=basic [redacted]'],
            'phone with trailing dot' => ['llamada a +525512345678.', 'llamada a [phone].'],
            'phone with trailing colon' => ['tel +525512345678: fallo', 'tel [phone]: fallo'],
            'bare 10-digit run (indistinguishable from an MX phone, masked)' => ['ts 1727517600 epoch', 'ts [phone] epoch'],
        ];
    }

    #[DataProvider('sensitiveStrings')]
    public function test_sanitize_masks_sensitive_patterns(string $input, string $expected): void
    {
        $this->assertSame($expected, RedactSensitiveLogData::sanitize($input));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function webhookUrls(): array
    {
        return [
            'slack' => ['cURL error 28 for https://hooks.slack.com/services/T0001/B0002/XyZsecret123 timed out', 'cURL error 28 for https://hooks.slack.com/[redacted] timed out'],
            'discord' => ['POST https://discord.com/api/webhooks/123456/tok-SECRET_abc failed', 'POST https://discord.com/[redacted] failed'],
            'discordapp' => ['POST https://discordapp.com/api/webhooks/123456/tok-SECRET_abc?wait=true failed', 'POST https://discordapp.com/[redacted] failed'],
            'zapier' => ['to https://hooks.zapier.com/hooks/catch/123/abcSECRET/ failed', 'to https://hooks.zapier.com/[redacted] failed'],
            'office webhook subdomain' => ['to https://acme.webhook.office.com/webhookb2/uuid@uuid/IncomingWebhook/SECRET/uuid failed', 'to https://acme.webhook.office.com/[redacted] failed'],
            'ptb discord' => ['POST https://ptb.discord.com/api/webhooks/1/x failed', 'POST https://ptb.discord.com/[redacted] failed'],
            'canary discord' => ['POST https://canary.discord.com/api/webhooks/1/x failed', 'POST https://canary.discord.com/[redacted] failed'],
            'custom tenant webhook' => ['https://example.org/hooks/tenant-7/s3cr3t', 'https://example.org/[redacted]'],
            'custom webhook with port' => ['http://10.0.0.5:8080/hook/abc', 'http://10.0.0.5:8080/[redacted]'],
            'curl sentence' => ['cURL error 28: timed out for https://example.org/hooks/t/s3cr3t', 'cURL error 28: timed out for https://example.org/[redacted]'],
            'aws execute-api is not s3' => ['to https://abc.execute-api.us-east-1.amazonaws.com/prod/hook/SECRET failed', 'to https://abc.execute-api.us-east-1.amazonaws.com/[redacted] failed'],
            'aws lambda url is not s3' => ['to https://x.lambda-url.us-east-1.on.aws.amazonaws.com/SECRET failed', 'to https://x.lambda-url.us-east-1.on.aws.amazonaws.com/[redacted] failed'],
            'lookalike suffix' => ['to https://evilsamsara.com/x/secret failed', 'to https://evilsamsara.com/[redacted] failed'],
            'suffix as subdomain of attacker' => ['to https://samsara.com.attacker.io/x/secret failed', 'to https://samsara.com.attacker.io/[redacted] failed'],
            'allowlisted host as userinfo' => ['to https://api.samsara.com@evil.com/sec/ret failed', 'to https://evil.com/[redacted] failed'],
            'outlook webhook' => ['to https://outlook.office.com/webhook/uuid@uuid/IncomingWebhook/SECRET/uuid failed', 'to https://outlook.office.com/[redacted] failed'],
        ];
    }

    #[DataProvider('webhookUrls')]
    public function test_sanitize_redacts_the_whole_path_of_webhook_urls(string $input, string $expected): void
    {
        $this->assertSame($expected, RedactSensitiveLogData::sanitize($input));
    }

    public function test_sanitize_keeps_the_path_of_allowlisted_provider_urls_and_bare_hosts(): void
    {
        $this->assertSame('GET https://api.samsara.com/fleet/vehicles failed', RedactSensitiveLogData::sanitize('GET https://api.samsara.com/fleet/vehicles failed'));
        $this->assertSame('GET https://api.samsara.com/fleet/vehicles/stats?[redacted] failed', RedactSensitiveLogData::sanitize('GET https://api.samsara.com/fleet/vehicles/stats?after=x failed'));
        $this->assertSame('GET https://x.s3.amazonaws.com/a/b.jpg failed', RedactSensitiveLogData::sanitize('GET https://x.s3.amazonaws.com/a/b.jpg failed'));
        $this->assertSame('GET https://s3.us-east-1.amazonaws.com/b/k failed', RedactSensitiveLogData::sanitize('GET https://s3.us-east-1.amazonaws.com/b/k failed'));
        $this->assertSame('GET https://s3-us-west-2.amazonaws.com/b/k failed', RedactSensitiveLogData::sanitize('GET https://s3-us-west-2.amazonaws.com/b/k failed'));
        $this->assertSame('GET https://api.samsara.com/fleet failed', RedactSensitiveLogData::sanitize('GET https://user:pw@api.samsara.com/fleet failed'));
        $this->assertSame('GET https://example.org failed', RedactSensitiveLogData::sanitize('GET https://example.org failed'));
    }

    public function test_sanitize_redacts_the_path_of_any_non_allowlisted_host(): void
    {
        $this->assertSame('GET https://discord.com/[redacted] failed', RedactSensitiveLogData::sanitize('GET https://discord.com/channels/1 failed'));
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
            'basic as a word' => ['Basic plan: basic checks passed'],
            'basic followed by a non-credential word' => ['the Basic tier was downgraded'],
            'url without query' => ['https://api.samsara.com/fleet/vehicles/stats'],
            '13-digit ms epoch' => ['ts 1727517600000 epoch'],
            '15-digit bare id' => ['vehicle 281474978683353 offline'],
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

    public function test_technical_name_keys_are_kept_but_people_and_user_written_names_are_redacted(): void
    {
        $out = RedactSensitiveLogData::redact([
            'route_name' => 'teams.switch',
            'model_name' => 'gpt-5-mini',
            'meter_name' => 'ai_tokens',
            'event_type_name' => 'harsh_brake',
            'feature_name' => 'copilot',
            'driver_name' => 'Juan Pérez',
            'team_name' => 'Transportes Ana',
            'display_name' => 'Ana',
            'full_name' => 'Ana Pérez',
            'report_name' => 'Reporte de Ana',
            'original_filename' => 'INE-ana-perez.pdf',
            'filename' => 'ana.jpg',
        ]);

        $this->assertSame('teams.switch', $out['route_name']);
        $this->assertSame('gpt-5-mini', $out['model_name']);
        $this->assertSame('ai_tokens', $out['meter_name']);
        $this->assertSame('harsh_brake', $out['event_type_name']);
        $this->assertSame('copilot', $out['feature_name']);

        foreach (['driver_name', 'team_name', 'display_name', 'full_name', 'report_name', 'original_filename', 'filename'] as $key) {
            $this->assertSame('[redacted]', $out[$key], $key);
        }
    }

    public function test_session_ids_are_redacted_even_though_id_is_a_technical_suffix(): void
    {
        $out = RedactSensitiveLogData::redact([
            'session_id' => 'f3Kx9aQ2pL7mN4vB8cD1eR6tY0uI5oP2',
            'sessionId' => 'f3Kx9aQ2pL7mN4vB8cD1eR6tY0uI5oP2',
            'laravel_session' => 'eyJpdiI6',
            'session' => ['_token' => 'x'],
            'session_count' => 3,
            'conversation_id' => 41,
        ]);

        $this->assertSame('[redacted]', $out['session_id']);
        $this->assertSame('[redacted]', $out['sessionId']);
        $this->assertSame('[redacted]', $out['laravel_session']);
        $this->assertSame('[redacted]', $out['session']);
        $this->assertSame(3, $out['session_count']);
        $this->assertSame(41, $out['conversation_id']);
        $this->assertSame(['session_id'], RedactSensitiveLogData::findings(['session_id' => 'abc', 'user_id' => 9]));
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

    public function test_secret_like_keys_ending_in_key_are_redacted_and_technical_keys_are_kept(): void
    {
        $out = RedactSensitiveLogData::redact([
            'secret_key' => 'a', 'private_key' => 'b', 'X-Api-Key' => 'c', 'access_key' => 'd', 'signing_key' => 'e',
            'event_key' => 'evt:1', 'url_key' => 'u', 'cache_key' => 'c', 'lock_key' => 'l', 'idempotency_key' => 'i', 'route_key' => 'r',
        ]);

        foreach (['secret_key', 'private_key', 'X-Api-Key', 'access_key', 'signing_key'] as $key) {
            $this->assertSame('[redacted]', $out[$key], $key);
        }
        foreach (['event_key', 'url_key', 'cache_key', 'lock_key', 'idempotency_key', 'route_key'] as $key) {
            $this->assertNotSame('[redacted]', $out[$key], $key);
        }
    }

    public function test_id_keys_keep_numeric_strings_but_other_keys_are_still_masked(): void
    {
        $out = RedactSensitiveLogData::redact([
            'vehicle_id' => '281474978683353',
            'samsara_event_id' => '1727517600000',
            'epoch_id' => '1727517600',
            'contact' => '+525512345678',
        ]);

        $this->assertSame('281474978683353', $out['vehicle_id']);
        $this->assertSame('1727517600000', $out['samsara_event_id']);
        $this->assertSame('1727517600', $out['epoch_id']);
        $this->assertSame('[phone]', $out['contact']);
    }

    public function test_objects_are_normalized_before_redaction(): void
    {
        $json = new class implements \JsonSerializable
        {
            public function jsonSerialize(): array
            {
                return ['email' => 'a@b.co', 'note' => 'llama a +525512345678', 'user_id' => 3];
            }
        };
        $arrayable = new class implements Arrayable
        {
            public function toArray(): array
            {
                return ['phone' => '5512345678', 'status' => 'ok'];
            }
        };
        $stringable = new class implements \Stringable
        {
            public function __toString(): string
            {
                return 'mail a@b.co';
            }
        };
        $plain = new class
        {
            public string $email = 'a@b.co';
        };
        $date = new DateTimeImmutable('2026-09-28T10:00:00Z');

        $out = RedactSensitiveLogData::redact(['j' => $json, 'a' => $arrayable, 's' => $stringable, 'p' => $plain, 'd' => $date]);

        $this->assertSame(['email' => '[redacted]', 'note' => 'llama a [phone]', 'user_id' => 3], $out['j']);
        $this->assertSame(['phone' => '[redacted]', 'status' => 'ok'], $out['a']);
        $this->assertSame('mail [email]', $out['s']);
        $this->assertSame(['object' => $plain::class], $out['p']);
        $this->assertSame($date, $out['d']);
        $this->assertStringNotContainsString('a@b.co', json_encode($out));
        $this->assertStringNotContainsString('5512345678', json_encode($out));
    }

    public function test_findings_reports_throwables_only_when_the_message_is_sensitive(): void
    {
        $this->assertSame([], RedactSensitiveLogData::findings(['exception' => new RuntimeException('connection refused')]));
        $this->assertSame(['exception'], RedactSensitiveLogData::findings(['exception' => new RuntimeException('falló +525512345678')]));
    }
}
