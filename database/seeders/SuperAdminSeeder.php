<?php

namespace Database\Seeders;

use App\Domains\Tenancy\Actions\SetGlobalRole;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\Concerns\DevelopmentOnly;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Siembra al operador SaaS de desarrollo: un usuario GLOBAL con
 * `global_role = super_admin` que NO es miembro de ningún tenant cliente.
 *
 * El super-admin es un rol de plataforma (columna `users.global_role`),
 * ortogonal a la membresía de teams: opera todos los tenants desde `/admin/*`
 * y los "impersona" (`HasTeams::forceSwitchTeam`) sin pertenecer a ellos. Por
 * eso NO se promueve al admin del tenant de prueba (`admin@serviexpress.test`):
 * ese usuario representa al cliente que contrata el servicio y debe ver sólo
 * su empresa, igual que en producción.
 *
 * Como en `DemoSeeder::createSuperAdmin()`, el operador recibe un team
 * personal para que al salir de una impersonación tenga a dónde volver.
 *
 * Si un entorno anterior ya había promovido al admin de ServiExpress, este
 * seeder le retira el rol para que la base converja al estado esperado.
 *
 * Idempotente: re-ejecutar deja usuario, team y rol exactamente igual.
 *
 * Run: `php artisan db:seed --class=SuperAdminSeeder`
 */
class SuperAdminSeeder extends Seeder
{
    use DevelopmentOnly;

    public const SUPER_ADMIN_EMAIL = 'superadmin@sam.test';

    public const SUPER_ADMIN_NAME = 'Operador SAM';

    public const SUPER_ADMIN_TEAM_NAME = 'SAM Operaciones';

    public const PASSWORD = 'password';

    /**
     * Admin del tenant de prueba que versiones anteriores promovían a
     * super-admin por comodidad. Se le retira el rol si aún lo tiene.
     */
    public const LEGACY_PROMOTED_EMAIL = 'admin@serviexpress.test';

    public function run(SetGlobalRole $setGlobalRole): void
    {
        if ($this->skipInProduction()) {
            return;
        }

        $operator = User::query()->firstOrCreate(
            ['email' => self::SUPER_ADMIN_EMAIL],
            [
                'name' => self::SUPER_ADMIN_NAME,
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
            ],
        );

        $setGlobalRole->execute($operator, true);

        if ($operator->personalTeam() === null) {
            $personal = Team::query()->create([
                'name' => self::SUPER_ADMIN_TEAM_NAME,
                'is_personal' => true,
            ]);
            $personal->members()->attach($operator, ['role' => TeamRole::Owner->value]);
        }

        if ($operator->current_team_id === null) {
            $operator->forceFill(['current_team_id' => $operator->personalTeam()?->id])->save();
        }

        $this->demoteLegacyTenantAdmin($setGlobalRole);

        $this->command?->info(
            'Super-admin global: '.self::SUPER_ADMIN_EMAIL.' / '.self::PASSWORD.' → consola SaaS en /admin/tenants.'
        );
    }

    private function demoteLegacyTenantAdmin(SetGlobalRole $setGlobalRole): void
    {
        $legacy = User::query()->where('email', self::LEGACY_PROMOTED_EMAIL)->first();

        if ($legacy === null || ! $legacy->isSuperAdmin()) {
            return;
        }

        $setGlobalRole->execute($legacy, false);

        $this->command?->warn(
            self::LEGACY_PROMOTED_EMAIL.' ya no es super-admin: el admin del tenant sólo ve su empresa.'
        );
    }
}
