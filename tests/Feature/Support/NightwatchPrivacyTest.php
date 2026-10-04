<?php

namespace Tests\Feature\Support;

use App\Models\User;
use App\Support\NightwatchPrivacy;
use App\Support\RedactLogChannel;
use Laravel\Nightwatch\Records\CacheEvent;
use Laravel\Nightwatch\Records\Command;
use Laravel\Nightwatch\Records\Exception;
use Laravel\Nightwatch\Records\Mail;
use Laravel\Nightwatch\Records\OutgoingRequest;
use Laravel\Nightwatch\Records\Request;
use Symfony\Component\HttpFoundation\FileBag;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\InputBag;
use Tests\TestCase;

/**
 * Nightwatch es un tercero: nada que identifique a una persona ni ninguna
 * credencial sale de SAM sin pasar por estos redactores.
 */
class NightwatchPrivacyTest extends TestCase
{
    private function request(string $url, string $routePath, string $ip = '203.0.113.57'): Request
    {
        return new Request(
            method: 'POST',
            url: $url,
            routeName: '',
            routeMethods: ['POST'],
            routeDomain: '',
            routePath: $routePath,
            routeAction: '',
            ip: $ip,
            duration: 10,
            statusCode: 200,
            requestSize: 0,
            responseSize: 0,
            headers: new HeaderBag,
            payload: new InputBag,
            files: new FileBag,
        );
    }

    public function test_the_webhook_endpoint_in_the_path_is_redacted(): void
    {
        $request = $this->request('https://app.sam.test/api/webhooks/9f8e7d6c5b4a', '/api/webhooks/{endpoint_url}');

        NightwatchPrivacy::redactRequest($request);

        $this->assertSame('https://app.sam.test/api/webhooks/[redacted]', $request->url);
    }

    public function test_onboarding_and_password_reset_tokens_are_redacted(): void
    {
        $onboarding = $this->request('https://app.sam.test/bienvenida/abcdef123456', '/bienvenida/{token}');
        $reset = $this->request('https://app.sam.test/reset-password/abcdef123456?email=ana%40cliente.mx', '/reset-password/{token}');
        $verify = $this->request('https://app.sam.test/email/verify/42/0a1b2c?expires=1&signature=deadbeef', '/email/verify/{id}/{hash}');

        NightwatchPrivacy::redactRequest($onboarding);
        NightwatchPrivacy::redactRequest($reset);
        NightwatchPrivacy::redactRequest($verify);

        $this->assertSame('https://app.sam.test/bienvenida/[redacted]', $onboarding->url);
        $this->assertSame('https://app.sam.test/reset-password/[redacted]?email=[redacted]', $reset->url);
        $this->assertSame('https://app.sam.test/email/verify/42/[redacted]?expires=[redacted]&signature=[redacted]', $verify->url);
    }

    public function test_ordinary_ids_stay_visible_but_query_values_do_not(): void
    {
        $request = $this->request('https://app.sam.test/acme/incidents/315?search=Juan%20P%C3%A9rez&page=2', '/{current_team}/incidents/{incident}');

        NightwatchPrivacy::redactRequest($request);

        $this->assertSame('https://app.sam.test/acme/incidents/315?search=[redacted]&page=[redacted]', $request->url);
    }

    public function test_the_path_falls_back_to_the_route_template_when_it_cannot_be_aligned(): void
    {
        $request = $this->request('https://app.sam.test/sub/api/webhooks/9f8e7d6c5b4a', '/api/webhooks/{endpoint_url}');

        NightwatchPrivacy::redactRequest($request);

        $this->assertSame('https://app.sam.test/api/webhooks/{endpoint_url}', $request->url);
    }

    public function test_the_client_ip_is_anonymized(): void
    {
        $v4 = $this->request('https://app.sam.test/', '/', '203.0.113.57');
        $v6 = $this->request('https://app.sam.test/', '/', '2001:db8:85a3:1:2:8a2e:370:7334');

        NightwatchPrivacy::redactRequest($v4);
        NightwatchPrivacy::redactRequest($v6);

        $this->assertSame('203.0.113.0', $v4->ip);
        $this->assertSame('2001:db8:85a3:1::', $v6->ip);
    }

    public function test_exception_messages_lose_emails_phones_and_tokens(): void
    {
        $exception = new Exception(
            class: 'RuntimeException',
            message: 'No se pudo avisar a ana@cliente.mx al +52 81 1765 8890 con Bearer sk_live_123',
            code: 0,
            file: 'app/Foo.php',
            line: 1,
            handled: true,
        );

        NightwatchPrivacy::redactException($exception);

        $this->assertStringNotContainsString('ana@cliente.mx', $exception->message);
        $this->assertStringNotContainsString('1765', $exception->message);
        $this->assertStringNotContainsString('sk_live_123', $exception->message);
    }

    public function test_outgoing_urls_keep_the_provider_path_but_never_presigned_queries_or_tenant_webhooks(): void
    {
        $samsara = new OutgoingRequest(method: 'GET', url: 'https://api.samsara.com/fleet/vehicles/stats?types=gps', duration: 1, requestSize: 0, responseSize: 0, statusCode: 200);
        $presigned = new OutgoingRequest(method: 'GET', url: 'http://rustfs:9000/sam/media/1.jpg?X-Amz-Signature=abc', duration: 1, requestSize: 0, responseSize: 0, statusCode: 200);
        $tenantWebhook = new OutgoingRequest(method: 'POST', url: 'https://hooks.slack.com/services/T000/B000/XXXX', duration: 1, requestSize: 0, responseSize: 0, statusCode: 200);

        NightwatchPrivacy::redactOutgoingRequest($samsara);
        NightwatchPrivacy::redactOutgoingRequest($presigned);
        NightwatchPrivacy::redactOutgoingRequest($tenantWebhook);

        $this->assertSame('https://api.samsara.com/fleet/vehicles/stats?[redacted]', $samsara->url);
        $this->assertStringNotContainsString('X-Amz-Signature', $presigned->url);
        $this->assertSame('https://hooks.slack.com/[redacted]', $tenantWebhook->url);
    }

    public function test_commands_mail_subjects_and_cache_keys_are_sanitized(): void
    {
        $command = new Command(class: 'X', name: 'sam:create-super-admin', command: 'sam:create-super-admin --email=ana@cliente.mx', exitCode: 0, duration: 1);
        $mail = new Mail(mailer: 'smtp', class: 'X', subject: 'Bienvenida ana@cliente.mx', to: 1, cc: 0, bcc: 0, attachments: 0, duration: 1, failed: false);
        $cache = new CacheEvent(store: 'redis', key: 'ana@cliente.mx|203.0.113.57', type: 'hit', duration: 1, ttl: 0);

        NightwatchPrivacy::redactCommand($command);
        NightwatchPrivacy::redactMail($mail);
        NightwatchPrivacy::redactCacheEvent($cache);

        $this->assertSame('sam:create-super-admin --email=[email]', $command->command);
        $this->assertSame('Bienvenida [email]', $mail->subject);
        $this->assertStringNotContainsString('ana@cliente.mx', $cache->key);
    }

    public function test_only_the_user_id_is_reported(): void
    {
        $user = User::factory()->make(['id' => 42, 'name' => 'Ana Pérez', 'email' => 'ana@cliente.mx']);

        $this->assertSame([], NightwatchPrivacy::userDetails($user));
    }

    public function test_the_nightwatch_log_channel_is_redacted(): void
    {
        $this->assertContains(RedactLogChannel::class, config('logging.channels.nightwatch.tap'));
    }

    public function test_request_payloads_are_never_captured_and_signature_headers_are_redacted(): void
    {
        $this->assertFalse(config('nightwatch.capture_request_payload'));

        foreach (['Authorization', 'Cookie', 'X-Twilio-Signature', 'X-Samsara-Signature', 'X-SAM-Signature'] as $header) {
            $this->assertContains($header, config('nightwatch.redact_headers'));
        }
    }
}
