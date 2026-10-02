<?php

namespace Database\Seeders;

use App\Domains\Tenancy\Actions\BootstrapSuperAdmin;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

/**
 * Única cuenta que se siembra en producción: el operador SaaS definido en
 * `config('auth.super_admin')` (env `SAM_SUPER_ADMIN_*`). Desde su consola
 * (`/admin/tenants`) se dan de alta los clientes; no se siembra ningún tenant.
 *
 * - Sin email configurado: avisa y no hace nada (no es error; se puede crear
 *   después con `php artisan sam:create-super-admin`).
 * - Sin contraseña (y la cuenta no existe): no la crea. Generar una e
 *   imprimirla la dejaría en los logs del deploy; se usa el comando
 *   interactivo `sam:create-super-admin`, que la pide sin eco.
 * - Contraseña débil (según `Password::defaults()`): aborta el seed para que
 *   el deploy lo note en vez de dejar una cuenta privilegiada débil.
 * - Cuenta existente: sólo garantiza rol/verificación/team; no toca la
 *   contraseña.
 */
class PlatformSuperAdminSeeder extends Seeder
{
    public function run(BootstrapSuperAdmin $bootstrap): void
    {
        /** @var array{email: ?string, name: ?string, password: ?string} $config */
        $config = config('auth.super_admin');

        $email = trim($config['email'] ?? '');

        if ($email === '') {
            $this->command?->warn('SAM_SUPER_ADMIN_EMAIL no está definido: no se creó el super-admin. Usa `php artisan sam:create-super-admin`.');

            return;
        }

        if (Validator::make(['email' => $email], ['email' => ['required', 'email']])->fails()) {
            throw new RuntimeException('SAM_SUPER_ADMIN_EMAIL no es un email válido.');
        }

        $exists = User::query()->where('email', mb_strtolower($email))->exists();
        $password = $config['password'] ?? '';

        if (! $exists && $password === '') {
            $this->command?->warn('SAM_SUPER_ADMIN_PASSWORD no está definido: no se creó el super-admin. Usa `php artisan sam:create-super-admin '.$email.'` (pide la contraseña sin eco).');

            return;
        }

        if (! $exists) {
            $validator = Validator::make(['password' => $password], ['password' => ['required', 'string', Password::default()]]);

            if ($validator->fails()) {
                throw new RuntimeException('SAM_SUPER_ADMIN_PASSWORD no cumple la política de contraseñas: '.implode(' ', $validator->errors()->all()));
            }
        }

        $name = trim($config['name'] ?? '');
        $name = $name !== '' ? $name : 'Operador SAM';

        ['user' => $user, 'created' => $created] = $bootstrap->execute($email, $name, $password);

        if (! $created) {
            $this->command?->info("Super-admin {$user->email} ya existía: rol y team garantizados, contraseña sin cambios.");

            return;
        }

        $this->command?->info("Super-admin creado: {$user->email} → consola en /admin/tenants. Quita SAM_SUPER_ADMIN_PASSWORD del entorno y activa 2FA al entrar (la consola lo exige).");
    }
}
