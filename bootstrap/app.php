<?php

use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RendersErrorsAsJson;
use App\Http\Middleware\RequireSuperAdminTwoFactor;
use App\Http\Middleware\SetTeamUrlDefaults;
use App\Http\Middleware\TrustProxiesFromConfig;
use App\Support\DeniedRequestLog;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
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
            'super_admin.two_factor' => RequireSuperAdminTwoFactor::class,
        ]);

        // RendersErrorsAsJson (copilot.stream) must run before the throttle
        // so a 429 is JSON too, not only policy/404/validation errors.
        $middleware->prependToPriorityList(
            before: [ThrottleRequests::class, ThrottleRequestsWithRedis::class],
            prepend: RendersErrorsAsJson::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Todo reporte de excepción lleva dónde ocurrió (nunca el payload).
        // El usuario ya lo añade Laravel (userId). Jamás debe enmascarar la
        // excepción original si resolver la ruta falla.
        $exceptions->context(function (): array {
            try {
                return array_filter(['route_name' => request()?->route()?->getName()]);
            } catch (Throwable) {
                return [];
            }
        });

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
