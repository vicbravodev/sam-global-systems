<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\SystemLog;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * La consola de plataforma (`/admin`) y la entrada de un super-admin a un
 * cliente ajeno son acceso cross-tenant: exigen verificación en dos pasos
 * CONFIRMADA (el criterio de Fortify con `confirm => true`: secreto y
 * `two_factor_confirmed_at`). Activada pero sin confirmar cuenta como sin 2FA.
 *
 * Sin 2FA: las páginas redirigen a la configuración de seguridad (que no pasa
 * por este middleware, así que no hay bucle) con un toast; JSON responde 403
 * con `reason: two_factor_required`. Se apaga con `SAM_ADMIN_REQUIRE_2FA=false`
 * (`auth.super_admin.require_two_factor`) sólo para un primer arranque.
 */
class RequireSuperAdminTwoFactor
{
    public const string REASON = 'two_factor_required';

    public const string MESSAGE = 'Para usar la consola de SAM necesitas activar la verificación en dos pasos.';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && self::mustSetUpTwoFactor($user)) {
            return self::deny($request, $user, 'admin_console');
        }

        return $next($request);
    }

    public static function mustSetUpTwoFactor(User $user): bool
    {
        return $user->isSuperAdmin()
            && config('auth.super_admin.require_two_factor') === true
            && ! $user->hasEnabledTwoFactorAuthentication();
    }

    /**
     * @param  'admin_console'|'tenant_entry'  $surface
     */
    public static function deny(Request $request, User $user, string $surface): Response
    {
        SystemLog::skipped('tenancy.admin_access.denied', self::REASON, input: [
            'user_id' => $user->id,
            'surface' => $surface,
            'route_name' => $request->route()?->getName(),
            'pending_confirmation' => $user->two_factor_secret !== null,
            'expects_json' => $request->expectsJson(),
        ]);

        if ($request->expectsJson()) {
            return response()->json(['message' => self::MESSAGE, 'reason' => self::REASON], 403);
        }

        Inertia::flash('toast', ['type' => 'warning', 'message' => self::MESSAGE]);

        return redirect()->route('security.edit');
    }
}
