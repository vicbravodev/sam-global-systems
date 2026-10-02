<?php

namespace App\Http\Controllers\Teams;

use App\Concerns\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use App\Support\SystemLog;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Primer acceso de un usuario dado de alta por el super-admin: el enlace del
 * correo de bienvenida (`TenantAccessInvitation`) trae un token del broker
 * `onboarding`. Definir la contraseña verifica el email (el enlace prueba la
 * posesión del buzón), inicia sesión y aterriza en su empresa.
 */
class TenantAccessController extends Controller
{
    use PasswordValidationRules;

    public function show(Request $request, string $token): Response
    {
        return Inertia::render('auth/set-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
            'team' => is_string($request->query('team')) ? $request->query('team') : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => $this->passwordRules(),
            'team' => ['nullable', 'string', 'max:255'],
        ]);

        $activated = null;

        $status = Password::broker('onboarding')->reset(
            [
                'email' => User::normalizeEmail((string) $request->input('email')),
                'token' => (string) $request->input('token'),
                'password' => (string) $request->input('password'),
                'password_confirmation' => (string) $request->input('password_confirmation'),
            ],
            function (User $user, string $password) use (&$activated): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();

                event(new PasswordReset($user));
                $activated = $user;
            },
        );

        if ($status !== Password::PASSWORD_RESET || ! $activated instanceof User) {
            SystemLog::skipped('tenancy.access_link.rejected', reason: is_string($status) ? str_replace('passwords.', '', $status) : 'unknown');

            throw ValidationException::withMessages([
                'email' => $status === Password::INVALID_TOKEN
                    ? 'El enlace ya se usó o venció. Pide a SAM que te reenvíe el acceso.'
                    : __(is_string($status) ? $status : 'passwords.token'),
            ]);
        }

        $landing = $this->landingTeam($activated, $request->input('team'));

        if ($landing !== null) {
            $activated->switchTeam($landing);
        }

        // Con 2FA confirmado, el enlace del correo NO sustituye al segundo
        // factor: la contraseña queda definida y entra por el login normal.
        if ($activated->two_factor_confirmed_at !== null) {
            SystemLog::ok('tenancy.access_link.activated',
                input: ['user_id' => $activated->id],
                result: ['landing_team_id' => $landing?->id, 'requires_two_factor' => true],
            );

            Inertia::flash('toast', ['type' => 'success', 'message' => 'Contraseña definida. Inicia sesión con tu segundo factor.']);

            return to_route('login');
        }

        Auth::login($activated);
        $request->session()->regenerate();

        SystemLog::ok('tenancy.access_link.activated',
            input: ['user_id' => $activated->id],
            result: ['landing_team_id' => $landing?->id, 'requires_two_factor' => false],
        );

        return $landing !== null
            ? to_route('dashboard', ['current_team' => $landing->slug])
            : redirect()->intended('/');
    }

    /**
     * La empresa del enlace si es miembro de ella (el slug viaja en la URL y
     * sólo se usa si la membresía existe); si no, su empresa más reciente.
     */
    private function landingTeam(User $user, mixed $invitedSlug): ?Team
    {
        $teams = $user->teams()->where('is_personal', false);

        if (is_string($invitedSlug) && $invitedSlug !== '') {
            $invited = (clone $teams)->where('teams.slug', $invitedSlug)->first();

            if ($invited instanceof Team) {
                return $invited;
            }
        }

        $team = $teams->orderByDesc('teams.id')->first();

        return $team instanceof Team ? $team : null;
    }
}
