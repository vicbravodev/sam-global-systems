<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\LoginResponse;
use App\Http\Responses\NeutralPasswordResetLinkResponse;
use App\Http\Responses\TwoFactorLoginResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse as FailedPasswordResetLinkRequestResponseContract;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse as SuccessfulPasswordResetLinkRequestResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(TwoFactorLoginResponseContract::class, TwoFactorLoginResponse::class);

        // Anti-enumeración (E4): el envío de enlace de restablecimiento responde
        // siempre con el mismo mensaje neutro, exista o no la cuenta.
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponseContract::class, NeutralPasswordResetLinkResponse::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponseContract::class, NeutralPasswordResetLinkResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->throttlePasswordResetRoutes();
    }

    /**
     * Fortify no expone limiter para forgot-password / reset-password: se lo
     * añadimos a sus rutas una vez registradas (sin throttle permitían spam de
     * correos de reset y fuerza bruta de tokens).
     */
    private function throttlePasswordResetRoutes(): void
    {
        $this->app->booted(function () {
            $routes = Route::getRoutes();
            $routes->refreshNameLookups();

            $routes->getByName('password.email')?->middleware('throttle:password-reset-link');
            $routes->getByName('password.update')?->middleware('throttle:password-reset');
        });
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        // Bucket por endpoint (= por tenant) + techo holgado por IP. Todo el
        // tráfico de Samsara llega desde pocas IPs: un único bucket por IP
        // haría que el flood de un tenant descartara pánicos de los demás.
        RateLimiter::for('webhooks', function (Request $request) {
            $endpoint = $request->route('endpoint_url');

            return [
                Limit::perMinute(600)->by('endpoint:'.(is_string($endpoint) ? $endpoint : $request->path())),
                Limit::perMinute(3000)->by('ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('password-reset-link', function (Request $request) {
            return [
                Limit::perMinute(5)->by('ip:'.$request->ip()),
                Limit::perHour(10)->by('email:'.Str::lower((string) $request->input('email'))),
            ];
        });

        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perMinute(5)->by('ip:'.$request->ip());
        });

        RateLimiter::for('api', function (Request $request) {
            // El parámetro llega como slug (string) o, si ya se resolvió el
            // binding, como Team; misma truthiness que el `?:` previo.
            $team = $request->route('current_team');
            $hasTeam = is_object($team) || (is_string($team) && $team !== '' && $team !== '0');

            return Limit::perMinute(60)->by($hasTeam ? $team : $request->ip());
        });

        RateLimiter::for('otp', function (Request $request) {
            $userId = $request->user()?->id;

            return Limit::perMinute(5)->by((string) ($userId !== null && $userId !== 0 ? $userId : $request->ip()));
        });
    }
}
