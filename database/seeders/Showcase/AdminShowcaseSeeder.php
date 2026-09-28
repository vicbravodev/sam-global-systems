<?php

namespace Database\Seeders\Showcase;

use App\Domains\Tenancy\Actions\SetGlobalRole;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Consola de administración (con `--with-extra-tenants`): operadores de
 * plataforma (super-admins con su team personal) y bitácora de acciones de
 * operador sobre los tenants extra (plan, suspensión, pagos, soporte), que
 * es lo que lista /admin/audit (sólo categorías seguridad y facturación).
 *
 * Idempotencia: usuarios por email; bitácora por `signature` determinista
 * (`insertOrIgnore`).
 */
class AdminShowcaseSeeder
{
    private const OPERATORS = [
        ['superadmin@sam.test', 'Sam Super Admin'],
        ['operaciones@sam.test', 'Olga Operaciones SAM'],
    ];

    public function run(?Command $command = null): void
    {
        $operators = [];

        foreach (self::OPERATORS as [$email, $name]) {
            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => Hash::make('password')]);
                $user->forceFill(['email_verified_at' => now()])->save();

                $personal = Team::query()->create(['name' => "Equipo de {$name}", 'is_personal' => true]);
                Membership::query()->create(['team_id' => $personal->id, 'user_id' => $user->id, 'role' => TeamRole::Owner->value]);
                $user->forceFill(['current_team_id' => $personal->id])->save();
            }

            app(SetGlobalRole::class)->execute($user, true);
            $operators[] = $user;
        }

        $this->seedOperatorAudit($operators);

        $command?->info('Consola admin: '.implode(', ', array_column(self::OPERATORS, 0)).' / password → /admin/tenants');
    }

    /**
     * @param  array<int, User>  $operators
     */
    private function seedOperatorAudit(array $operators): void
    {
        $entries = [
            ['transportes-del-norte', 'subscription.plan_changed', 'billing', 'Plan cambiado de Pro a Enterprise por crecimiento de flota.', 25],
            ['transportes-del-norte', 'invoice.marked_paid', 'billing', 'Factura de agosto marcada como pagada (SPEI verificado).', 22],
            ['logistica-bajio', 'subscription.trial_extended', 'billing', 'Prueba extendida 7 días: el cliente sigue integrando Samsara.', 3],
            ['logistica-bajio', 'tenant.member_added', 'security', 'Operador SAM añadió al dueño de la cuenta como owner.', 5],
            ['fletes-express-sur', 'subscription.past_due', 'billing', 'Suscripción marcada como vencida: factura sin pago tras 15 días.', 6],
            ['fletes-express-sur', 'impersonation.started', 'security', 'Operador SAM entró como el tenant para soporte (ticket #4821).', 2],
            ['fletes-express-sur', 'impersonation.ended', 'security', 'Fin de la sesión de soporte como el tenant.', 2],
        ];

        foreach ($entries as $i => [$slug, $action, $category, $summary, $daysAgo]) {
            $team = Team::query()->where('slug', $slug)->first();

            if ($team === null) {
                continue;
            }

            $operator = $operators[$i % count($operators)];
            $at = now()->subDays($daysAgo)->setTime(11, 15 + $i);

            TenantContext::for($team->id, fn () => DB::table('audit_logs')->insertOrIgnore([
                'team_id' => $team->id,
                'actor_type' => 'user',
                'actor_id' => $operator->id,
                'action' => $action,
                'category' => $category,
                'entity_type' => 'Team',
                'entity_id' => $team->id,
                'source_type' => 'admin_console',
                'source_reference_id' => null,
                'signature' => "showcase:admin:{$slug}:{$action}",
                'summary' => $summary,
                'metadata_json' => json_encode(['actor_email' => $operator->email, 'showcase' => true]),
                'ip_address' => '187.190.12.'.(20 + $i),
                'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) Safari/17.5',
                'occurred_at' => $at,
                'created_at' => $at,
            ]));
        }
    }
}
