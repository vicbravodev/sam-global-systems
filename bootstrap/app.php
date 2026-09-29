<?php

use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetTeamUrlDefaults;
use App\Http\Middleware\TrustProxiesFromConfig;
use App\Support\DeniedRequestLog;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
        ]);

        // TRUSTED_PROXIES (config/app.php): IPs/CIDRs del balanceador, o '*'.
        $middleware->replace(TrustProxies::class, TrustProxiesFromConfig::class);

        // Invalida las demás sesiones cuando cambia la contraseña (cambio o
        // reset): compara el hash guardado en la sesión con el actual.
        $middleware->authenticateSessions();

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SetTeamUrlDefaults::class,
        ]);

        $middleware->alias([
            'ensure.super_admin' => EnsureSuperAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Todo reporte de excepción lleva quién y dónde (nunca el payload).
        $exceptions->context(fn (): array => array_filter([
            'user_id' => request()?->user()?->getAuthIdentifier(),
            'route_name' => request()?->route()?->getName(),
        ]));

        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            $status = $response->getStatusCode();

            DeniedRequestLog::record($exception, $request, $status);

            if (! in_array($status, [403, 404, 500, 503], true)
                || $request->expectsJson()
                || $request->is('api/*')
                || $request->is('webhooks/*')) {
                return $response;
            }

            // Server errors keep the framework's debug page while developing.
            if (in_array($status, [500, 503], true) && config('app.debug')) {
                return $response;
            }

            return Inertia::render('errors/error', ['status' => $status])
                ->toResponse($request)
                ->setStatusCode($status);
        });
    })->create();
