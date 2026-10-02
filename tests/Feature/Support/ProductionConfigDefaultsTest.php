<?php

namespace Tests\Feature\Support;

use Tests\TestCase;

/**
 * Defaults de los archivos de config cuando el entorno de producción no fija
 * la variable (o la deja vacía, como un .env copiado de .env.example). Evalúa
 * cada archivo de config con un entorno controlado, sin depender del .env de
 * quien corre el test ni del de CI.
 */
class ProductionConfigDefaultsTest extends TestCase
{
    /** @var array<string, array{env: mixed, server: mixed, putenv: string|false}> */
    private array $savedEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $saved) {
            $this->restoreVariable($key, $saved);
        }

        $this->savedEnv = [];

        parent::tearDown();
    }

    public function test_session_cookie_is_secure_by_default_in_production(): void
    {
        $session = $this->configFile('session', ['APP_ENV' => 'production', 'SESSION_SECURE_COOKIE' => null]);

        $this->assertTrue($session['secure']);
        $this->assertTrue($session['http_only']);
        $this->assertSame('lax', $session['same_site']);
    }

    public function test_an_empty_secure_cookie_value_still_means_secure_in_production(): void
    {
        $this->assertTrue($this->configFile('session', ['APP_ENV' => 'production', 'SESSION_SECURE_COOKIE' => ''])['secure']);
    }

    public function test_an_explicit_secure_cookie_value_wins(): void
    {
        $this->assertFalse($this->configFile('session', ['APP_ENV' => 'production', 'SESSION_SECURE_COOKIE' => 'false'])['secure']);
        $this->assertTrue($this->configFile('session', ['APP_ENV' => 'local', 'SESSION_SECURE_COOKIE' => 'true'])['secure']);
    }

    public function test_session_cookie_is_not_secure_by_default_outside_production(): void
    {
        $this->assertFalse($this->configFile('session', ['APP_ENV' => 'local', 'SESSION_SECURE_COOKIE' => null])['secure']);
    }

    public function test_production_logs_rotate_daily_at_info_level_by_default(): void
    {
        $logging = $this->configFile('logging', [
            'APP_ENV' => 'production',
            'LOG_CHANNEL' => null,
            'LOG_STACK' => null,
            'LOG_LEVEL' => null,
            'LOG_JSON_LEVEL' => null,
            'LOG_JSON_STDERR' => null,
        ]);

        $this->assertSame('stack', $logging['default']);
        $this->assertSame(['daily', 'json'], $logging['channels']['stack']['channels']);
        $this->assertNotContains('single', $logging['channels']['stack']['channels']);
        $this->assertSame('info', $logging['channels']['daily']['level']);
        $this->assertSame('daily', $logging['channels']['json']['driver']);
        $this->assertSame('info', $logging['channels']['json']['level']);
        $this->assertSame('info', $logging['channels']['stderr']['level']);
    }

    public function test_development_keeps_single_file_and_debug_level(): void
    {
        $logging = $this->configFile('logging', ['APP_ENV' => 'local', 'LOG_STACK' => null, 'LOG_LEVEL' => null, 'LOG_JSON_LEVEL' => null]);

        $this->assertSame(['single', 'json'], $logging['channels']['stack']['channels']);
        $this->assertSame('debug', $logging['channels']['single']['level']);
        $this->assertSame('debug', $logging['channels']['json']['level']);
    }

    public function test_explicit_log_settings_win_in_production(): void
    {
        $logging = $this->configFile('logging', ['APP_ENV' => 'production', 'LOG_STACK' => 'stderr,json', 'LOG_LEVEL' => 'warning', 'LOG_JSON_LEVEL' => null]);

        $this->assertSame(['stderr', 'json'], $logging['channels']['stack']['channels']);
        $this->assertSame('warning', $logging['channels']['stderr']['level']);
        $this->assertSame('warning', $logging['channels']['json']['level']);
    }

    public function test_debug_is_off_unless_the_environment_turns_it_on(): void
    {
        $this->assertFalse($this->configFile('app', ['APP_ENV' => 'production', 'APP_DEBUG' => null])['debug']);
        $this->assertNull($this->configFile('app', ['TRUSTED_PROXIES' => null])['trusted_proxies']);
    }

    public function test_ssr_can_be_turned_off_where_no_ssr_process_runs(): void
    {
        $this->assertTrue($this->configFile('inertia', ['INERTIA_SSR_ENABLED' => null, 'INERTIA_SSR_URL' => null])['ssr']['enabled']);
        $this->assertSame('http://127.0.0.1:13714', $this->configFile('inertia', ['INERTIA_SSR_URL' => null])['ssr']['url']);

        $ssr = $this->configFile('inertia', ['INERTIA_SSR_ENABLED' => 'false', 'INERTIA_SSR_URL' => 'http://ssr:13714'])['ssr'];

        $this->assertFalse($ssr['enabled']);
        $this->assertSame('http://ssr:13714', $ssr['url']);
    }

    /**
     * Evalúa `config/{name}.php` con las variables dadas (null = ausente).
     *
     * @param  array<string, string|null>  $env
     * @return array<string, mixed>
     */
    private function configFile(string $name, array $env): array
    {
        foreach ($env as $key => $value) {
            $this->savedEnv[$key] ??= [
                'env' => $_ENV[$key] ?? null,
                'server' => $_SERVER[$key] ?? null,
                'putenv' => getenv($key),
            ];

            $this->setVariable($key, $value);
        }

        /** @var array<string, mixed> $config */
        $config = require config_path("{$name}.php");

        return $config;
    }

    private function setVariable(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            return;
        }

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }

    /**
     * @param  array{env: mixed, server: mixed, putenv: string|false}  $saved
     */
    private function restoreVariable(string $key, array $saved): void
    {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);

        if ($saved['env'] !== null) {
            $_ENV[$key] = $saved['env'];
        }

        if ($saved['server'] !== null) {
            $_SERVER[$key] = $saved['server'];
        }

        if ($saved['putenv'] !== false) {
            putenv("{$key}={$saved['putenv']}");
        }
    }
}
