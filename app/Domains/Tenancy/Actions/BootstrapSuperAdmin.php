<?php

namespace App\Domains\Tenancy\Actions;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\SystemLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Crea (o converge) al operador SaaS: usuario con email verificado,
 * `global_role = super_admin` y un team personal "SAM Operaciones" al que
 * volver tras impersonar. Es la única forma soportada de tener el PRIMER
 * super-admin en una base vacía (el registro público está cerrado y
 * `sam:promote-super-admin` exige un usuario existente).
 *
 * Idempotente: si el usuario ya existe sólo garantiza rol, verificación y
 * team personal; NUNCA le cambia la contraseña (re-sembrar producción no
 * debe pisar la contraseña que el operador ya eligió).
 */
class BootstrapSuperAdmin
{
    public const string PERSONAL_TEAM_NAME = 'SAM Operaciones';

    public function __construct(private readonly SetGlobalRole $setGlobalRole) {}

    /**
     * @return array{user: User, created: bool}
     */
    public function execute(string $email, string $name, string $password): array
    {
        $email = mb_strtolower(trim($email));

        return DB::transaction(function () use ($email, $name, $password): array {
            $user = User::query()->where('email', $email)->first();
            $created = $user === null;

            if ($user === null) {
                $user = new User;
                $user->forceFill([
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make($password),
                    'email_verified_at' => now(),
                ])->save();
            } elseif ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $this->setGlobalRole->execute($user, true);

            $personal = $user->personalTeam();

            if ($personal === null) {
                $personal = Team::query()->create([
                    'name' => self::PERSONAL_TEAM_NAME,
                    'is_personal' => true,
                ]);
                $personal->members()->attach($user, ['role' => TeamRole::Owner->value]);
            }

            if ($user->current_team_id === null) {
                $user->forceFill(['current_team_id' => $personal->id])->save();
            }

            SystemLog::ok('tenancy.super_admin.bootstrapped',
                input: ['user_id' => $user->id],
                result: ['created' => $created, 'personal_team_id' => $personal->id],
            );

            return ['user' => $user, 'created' => $created];
        });
    }
}
