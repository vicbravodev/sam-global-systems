<?php

namespace App\Console\Commands;

use App\Domains\Tenancy\Actions\BootstrapSuperAdmin;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Crea el super-admin (operador SaaS) en una base vacía, pidiendo la
 * contraseña sin eco para que no quede en env ni en el historial del shell.
 * Si el usuario ya existe, sólo le garantiza el rol y el team personal.
 */
class CreateSuperAdmin extends Command
{
    protected $signature = 'sam:create-super-admin {email? : Email del operador} {--name= : Nombre visible}';

    protected $description = 'Crea (o converge) el super-admin de la plataforma con email verificado y team personal';

    public function handle(BootstrapSuperAdmin $bootstrap): int
    {
        $argument = $this->argument('email');
        $email = is_string($argument) && $argument !== '' ? $argument : text(
            label: 'Email del super-admin',
            required: true,
            validate: fn (string $value): ?string => filter_var($value, FILTER_VALIDATE_EMAIL) === false ? 'Email inválido.' : null,
        );

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Email inválido.');

            return self::FAILURE;
        }

        $existing = User::query()->where('email', mb_strtolower(trim($email)))->first();

        if ($existing !== null) {
            $bootstrap->execute($email, $existing->name, '');
            $this->info("{$existing->email} ya existía: ahora es super-admin (contraseña sin cambios).");
            $this->remindTwoFactor();

            return self::SUCCESS;
        }

        $option = $this->option('name');
        $name = is_string($option) && $option !== '' ? $option : text(label: 'Nombre', default: 'Operador SAM', required: true);

        $secret = password(
            label: 'Contraseña',
            required: true,
            validate: fn (string $value): ?string => $this->passwordError($value),
        );

        $confirmation = password(label: 'Confirma la contraseña', required: true);

        if (! hash_equals($secret, $confirmation)) {
            $this->error('Las contraseñas no coinciden.');

            return self::FAILURE;
        }

        if (($error = $this->passwordError($secret)) !== null) {
            $this->error($error);

            return self::FAILURE;
        }

        ['user' => $user] = $bootstrap->execute($email, $name, $secret);

        $this->info("Super-admin creado: {$user->email}. Entra en /login → consola en /admin/tenants.");
        $this->remindTwoFactor();

        return self::SUCCESS;
    }

    /**
     * La consola /admin exige 2FA confirmado (RequireSuperAdminTwoFactor):
     * sin él, el primer login termina en la página de seguridad.
     */
    private function remindTwoFactor(): void
    {
        if (config('auth.super_admin.require_two_factor') !== true) {
            return;
        }

        $this->warn('Recuerda: la consola /admin exige verificación en dos pasos. Actívala en /settings/security al entrar por primera vez.');
    }

    private function passwordError(string $value): ?string
    {
        $validator = Validator::make(['password' => $value], ['password' => ['required', 'string', Password::default()]]);

        return $validator->fails() ? implode(' ', $validator->errors()->all()) : null;
    }
}
