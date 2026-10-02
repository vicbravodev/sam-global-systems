<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Tenancy\Actions\SetGlobalRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\SystemLog;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manages the set of SaaS operators (global super-admins). Promotion/demotion is
 * audited under the security category. Guard rails prevent self-demotion and
 * removing the last operator.
 */
class OperatorController extends Controller
{
    public function __construct(private readonly RecordAuditEntry $audit) {}

    public function index(#[CurrentUser] User $actor): Response
    {
        $operators = User::query()
            ->where('global_role', 'super_admin')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'two_factor_confirmed_at', 'created_at'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'isYou' => $user->id === $actor->id,
                // Un operador ve y opera a TODOS los clientes: sin 2FA es el
                // eslabón más débil de la plataforma.
                'twoFactor' => $user->two_factor_confirmed_at !== null,
                'createdAt' => $user->created_at?->toIso8601String(),
            ])->values()->all();

        return Inertia::render('admin/operators/index', [
            'operators' => $operators,
        ]);
    }

    public function store(Request $request, SetGlobalRole $setGlobalRole, #[CurrentUser] User $actor): RedirectResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => User::normalizeEmail($request->input('email'))]);
        }

        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::findByEmail($data['email']);

        if ($user === null) {
            $this->reject('account_not_found', $actor, null, 'grant');

            throw ValidationException::withMessages([
                'email' => 'No hay una cuenta con ese correo. Crea la cuenta del operador con `php artisan sam:create-super-admin`.',
            ]);
        }

        if ($user->isSuperAdmin()) {
            $this->reject('already_super_admin', $actor, $user, 'grant');

            throw ValidationException::withMessages([
                'email' => 'El usuario ya es super-admin.',
            ]);
        }

        $setGlobalRole->execute($user, true);

        SystemLog::ok('access.operator.granted', input: ['actor_id' => $actor->id, 'user_id' => $user->id]);

        $this->record($request, $actor, 'super-admin.promoted', $user,
            "{$user->email} promovido a super-admin.");

        $this->toast('Operador promovido.');

        return redirect()->route('admin.operators.index');
    }

    public function destroy(Request $request, User $user, SetGlobalRole $setGlobalRole, #[CurrentUser] User $actor): RedirectResponse
    {

        if ($actor->id === $user->id) {
            $this->reject('self_revocation', $actor, $user, 'revoke');

            throw ValidationException::withMessages([
                'operator' => 'No puedes quitarte el rol a ti mismo.',
            ]);
        }

        if (User::where('global_role', 'super_admin')->count() <= 1) {
            $this->reject('last_super_admin', $actor, $user, 'revoke');

            throw ValidationException::withMessages([
                'operator' => 'Debe quedar al menos un super-admin.',
            ]);
        }

        $setGlobalRole->execute($user, false);

        SystemLog::ok('access.operator.revoked', input: ['actor_id' => $actor->id, 'user_id' => $user->id]);

        $this->record($request, $actor, 'super-admin.demoted', $user,
            "{$user->email} degradado de super-admin.");

        $this->toast('Operador degradado.');

        return redirect()->route('admin.operators.index');
    }

    private function reject(string $reason, User $actor, ?User $target, string $change): void
    {
        SystemLog::skipped('access.operator.rejected', reason: $reason, input: [
            'actor_id' => $actor->id,
            'user_id' => $target?->id,
            'change' => $change,
        ]);
    }

    private function record(Request $request, User $actor, string $action, User $target, string $summary): void
    {
        $this->audit->execute(
            actorType: AuditActorType::User,
            actorId: $actor->id,
            action: $action,
            category: AuditCategory::Security,
            entityType: User::class,
            entityId: $target->id,
            summary: $summary,
            metadata: ['actor_email' => $actor->email, 'target_email' => $target->email],
            signature: $action.':'.$target->id.':'.Str::uuid()->toString(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );
    }
}
