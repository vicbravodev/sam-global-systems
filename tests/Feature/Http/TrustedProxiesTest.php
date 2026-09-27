<?php

namespace Tests\Feature\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Detrás del balanceador TLS la app debe ver el esquema e IP reales del
 * cliente (firma de Twilio sobre fullUrl, throttles por IP), pero sólo si el
 * proxy está declarado en TRUSTED_PROXIES.
 */
class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_test/proxy-echo', fn (Request $request) => [
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
            'url' => $request->fullUrl(),
        ]);
    }

    private function viaProxy()
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->withHeaders([
                'X-Forwarded-For' => '203.0.113.7',
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'app.sam.test',
                'X-Forwarded-Port' => '443',
            ])
            ->getJson('/_test/proxy-echo');
    }

    public function test_forwarded_headers_are_ignored_by_default(): void
    {
        config(['app.trusted_proxies' => null]);

        $this->viaProxy()
            ->assertJson(['ip' => '10.0.0.5', 'secure' => false]);
    }

    public function test_forwarded_headers_are_honoured_for_configured_proxy(): void
    {
        config(['app.trusted_proxies' => '10.0.0.0/8']);

        $this->viaProxy()
            ->assertJson([
                'ip' => '203.0.113.7',
                'secure' => true,
                'url' => 'https://app.sam.test/_test/proxy-echo',
            ]);
    }

    public function test_wildcard_trusts_the_immediate_proxy(): void
    {
        config(['app.trusted_proxies' => '*']);

        $this->viaProxy()->assertJson(['ip' => '203.0.113.7', 'secure' => true]);
    }
}
