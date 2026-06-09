<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Tenancy\Actions\SetGlobalRole;
use App\Http\Controllers\Controller;
use App\Models\User;
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
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
            ])->values()->all();

        return Inertia::render('admin/operators/index', [
            'operators' => $operators,
        ]);
    }

    public function store(Request $request, SetGlobalRole $setGlobalRole): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
        ]);

        $user = User::where('email', $data['email'])->firstOrFail();

        if ($user->isSuperAdmin()) {
            throw ValidationException::withMessages([
                'email' => 'El usuario ya es super-admin.',
            ]);
        }

        $setGlobalRole->execute($user, true);

        $this->record($request, 'super-admin.promoted', $user,
            "{$user->email} promovido a super-admin.");

        return redirect()->route('admin.operators.index')->with('status', 'Operador promovido.');
    }

    public function destroy(Request $request, User $user, SetGlobalRole $setGlobalRole): RedirectResponse
    {
        $actor = $request->user();

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

        $this->record($request, 'super-admin.demoted', $user,
            "{$user->email} degradado de super-admin.");

        return redirect()->route('admin.operators.index')->with('status', 'Operador degradado.');
    }

    private function record(Request $request, string $action, User $target, string $summary): void
    {
        $actor = $request->user();

        $this->audit->execute(
            actorType: AuditActorType::User,
            actorId: (int) $actor->id,
            action: $action,
            category: AuditCategory::Security,
            entityType: User::class,
            entityId: (int) $target->id,
            summary: $summary,
            metadata: ['actor_email' => $actor->email, 'target_email' => $target->email],
            signature: $action.':'.$target->id.':'.Str::uuid()->toString(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );
    }
}
