<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Tenancy\Actions\SetGlobalRole;
use App\Http\Controllers\Controller;
use App\Models\User;
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

    public function index(): Response
    {
        $operators = User::query()
            ->where('global_role', 'super_admin')
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
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
            throw ValidationException::withMessages([
                'email' => __('validation.exists', ['attribute' => 'email']),
            ]);
        }

        if ($user->isSuperAdmin()) {
            throw ValidationException::withMessages([
                'email' => 'El usuario ya es super-admin.',
            ]);
        }

        $setGlobalRole->execute($user, true);

        $this->record($request, $actor, 'super-admin.promoted', $user,
            "{$user->email} promovido a super-admin.");

        $this->toast('Operador promovido.');

        return redirect()->route('admin.operators.index');
    }

    public function destroy(Request $request, User $user, SetGlobalRole $setGlobalRole, #[CurrentUser] User $actor): RedirectResponse
    {

        if ($actor->id === $user->id) {
            throw ValidationException::withMessages([
                'operator' => 'No puedes quitarte el rol a ti mismo.',
            ]);
        }

        if (User::where('global_role', 'super_admin')->count() <= 1) {
            throw ValidationException::withMessages([
                'operator' => 'Debe quedar al menos un super-admin.',
            ]);
        }

        $setGlobalRole->execute($user, false);

        $this->record($request, $actor, 'super-admin.demoted', $user,
            "{$user->email} degradado de super-admin.");

        $this->toast('Operador degradado.');

        return redirect()->route('admin.operators.index');
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
