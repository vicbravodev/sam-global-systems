<?php

namespace Database\Seeders;

use App\Domains\Tenancy\Actions\SetGlobalRole;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Marca al usuario admin del tenant de prueba como super-admin global para
 * poder auditar/usar el panel `/admin/*` en desarrollo (R4.3 / F4.1).
 *
 * Sin esto, `admin@serviexpress.test` no tiene `global_role=super_admin` y toda
 * visita a `/admin/*` cae en 403 (EnsureSuperAdmin), bloqueando la auditoría del
 * panel del operador SaaS.
 *
 * Depende de que SamsaraTestSeeder ya haya creado al usuario, por eso se llama
 * DESPUÉS de él en DatabaseSeeder. Si el usuario no existe (p.ej. al correr este
 * seeder de forma aislada en un entorno sin SamsaraTestSeeder), no hace nada.
 *
 * Idempotente: re-ejecutar deja el rol exactamente igual.
 *
 * Run: `php artisan db:seed --class=SuperAdminSeeder`
 */
class SuperAdminSeeder extends Seeder
{
    public const SUPER_ADMIN_EMAIL = 'admin@serviexpress.test';

    public function run(SetGlobalRole $setGlobalRole): void
    {
        $user = User::query()->where('email', self::SUPER_ADMIN_EMAIL)->first();

        if ($user === null) {
            $this->command?->warn(
                'SuperAdminSeeder: no existe '.self::SUPER_ADMIN_EMAIL.
                ' (¿corriste SamsaraTestSeeder antes?). Se omite.'
            );

            return;
        }

        $setGlobalRole->execute($user, true);

        $this->command?->info('Super-admin global: '.self::SUPER_ADMIN_EMAIL.' puede entrar a /admin/*.');
    }
}
