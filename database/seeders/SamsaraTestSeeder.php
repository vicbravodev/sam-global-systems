<?php

namespace Database\Seeders;

use App\Domains\Access\Models\Role;
use App\Domains\Integrations\Enums\IntegrationProviderStatus;
use App\Domains\Integrations\Enums\IntegrationProviderType;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Seeder mínimo para probar manualmente la integración con Samsara.
 *
 * Crea SOLO lo imprescindible para entrar a la UI y registrar una API key
 * de Samsara tú mismo desde el formulario de integraciones:
 *
 *   - Un tenant (Team) "Samsara Test Fleet".
 *   - Usuarios para hacer login (admin + monitorista).
 *   - El catálogo IntegrationProvider de Samsara (activo) para que aparezca
 *     en el selector de "crear provider".
 *
 * NO crea el TenantIntegration (la conexión con la API key): eso lo haces tú
 * desde la UI, que es justo lo que quieres validar.
 *
 * Run: `php artisan db:seed --class=SamsaraTestSeeder`
 *
 * Idempotente: re-ejecutar no duplica nada (team, users y provider usan
 * updateOrCreate / lookup por email).
 */
class SamsaraTestSeeder extends Seeder
{
    private const TEAM_SLUG = 'samsara-test';

    private const TEAM_NAME = 'Samsara Test Fleet';

    private const PASSWORD = 'password';

    /**
     * @var array<int, array{email: string, name: string, rbac_role: string, team_role: TeamRole}>
     */
    private const USERS = [
        ['email' => 'admin@samsara.test', 'name' => 'Admin Samsara', 'rbac_role' => 'tenant_admin', 'team_role' => TeamRole::Owner],
        ['email' => 'monitor@samsara.test', 'name' => 'Monitor Samsara', 'rbac_role' => 'monitorista', 'team_role' => TeamRole::Member],
    ];

    public function run(): void
    {
        // RBAC roles (tenant_admin, monitorista, ...) viven en AccessSeeder.
        $this->call(AccessSeeder::class);

        DB::transaction(function () {
            $team = $this->createTeam();
            $this->createUsers($team);
            $this->ensureSamsaraProvider();
        });

        $this->command?->info("\nTenant de prueba listo [".self::TEAM_SLUG.'].');
        $this->command?->info('Login: admin@samsara.test / '.self::PASSWORD.' (también monitor@samsara.test).');
        $this->command?->info('Ve a Integraciones → crear provider → Samsara y registra tu API key.');
    }

    private function createTeam(): Team
    {
        return Team::query()->withTrashed()->updateOrCreate(
            ['slug' => self::TEAM_SLUG],
            [
                'name' => self::TEAM_NAME,
                'is_personal' => false,
                'timezone' => 'America/Mexico_City',
                'country' => 'MX',
                'currency' => 'mxn',
                'deleted_at' => null,
            ],
        );
    }

    private function createUsers(Team $team): void
    {
        foreach (self::USERS as $userData) {
            $user = User::query()->where('email', $userData['email'])->first();

            if (! $user) {
                $user = User::query()->create([
                    'name' => $userData['name'],
                    'email' => $userData['email'],
                    'password' => Hash::make(self::PASSWORD),
                    'email_verified_at' => now(),
                ]);
            }

            $this->attachUserToTeam($user, $team, $userData['team_role'], $userData['rbac_role']);
        }
    }

    private function attachUserToTeam(User $user, Team $team, TeamRole $teamRole, string $rbacRoleCode): void
    {
        if (! $user->teams()->where('teams.id', $team->id)->exists()) {
            $team->members()->attach($user, ['role' => $teamRole->value]);
        } else {
            Membership::query()
                ->where('team_id', $team->id)
                ->where('user_id', $user->id)
                ->update(['role' => $teamRole->value]);
        }

        $role = Role::query()->where('code', $rbacRoleCode)->first();

        if ($role) {
            Membership::query()
                ->where('team_id', $team->id)
                ->where('user_id', $user->id)
                ->update(['role_id' => $role->id]);
        }

        $user->forceFill(['current_team_id' => $team->id])->save();
    }

    private function ensureSamsaraProvider(): IntegrationProvider
    {
        return IntegrationProvider::query()->updateOrCreate(
            ['code' => 'samsara'],
            [
                'name' => 'Samsara',
                'type' => IntegrationProviderType::Telematics,
                'status' => IntegrationProviderStatus::Active,
                'capabilities_json' => ['gps', 'diagnostics', 'driver_behavior'],
            ],
        );
    }
}
