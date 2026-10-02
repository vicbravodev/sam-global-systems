<?php

namespace App\Domains\Tenancy\Actions;

use App\Actions\Teams\CreateTeam;
use App\Models\User;
use App\Support\SystemLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Resuelve el usuario que el super-admin da de alta en un tenant (dueño de
 * una empresa nueva o miembro añadido desde la consola). Si el email no
 * existe, crea la cuenta SIN acceso utilizable: contraseña aleatoria que nadie
 * conoce y email sin verificar. El acceso real lo da el enlace de bienvenida
 * (`SendTenantAccessLink`), que al usarse verifica el correo. Así
 * `email_verified_at = null` significa "aún no activó su cuenta".
 *
 * Mantiene la invariante de "todo usuario tiene team personal", pero el
 * llamador decide a qué team aterriza (normalmente el tenant).
 */
class ProvisionTenantUser
{
    public function __construct(private readonly CreateTeam $createTeam) {}

    /**
     * @return array{user: User, created: bool}
     */
    public function execute(string $email, ?string $name): array
    {
        $email = mb_strtolower(trim($email));
        $existing = User::findByEmail($email);

        if ($existing !== null) {
            return ['user' => $existing, 'created' => false];
        }

        $name = trim((string) $name);

        if ($name === '') {
            throw ValidationException::withMessages([
                'owner_name' => 'El nombre es obligatorio para dar de alta a un usuario nuevo.',
                'name' => 'El nombre es obligatorio para dar de alta a un usuario nuevo.',
            ]);
        }

        return DB::transaction(function () use ($email, $name): array {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Str::password(32),
            ]);

            $personal = $this->createTeam->handle($user, $name, isPersonal: true);

            SystemLog::ok('tenancy.user.provisioned',
                input: ['user_id' => $user->id],
                result: ['personal_team_id' => $personal->id],
            );

            return ['user' => $user, 'created' => true];
        });
    }
}
