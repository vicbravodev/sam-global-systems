<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Tenancy\Actions\UpdateDemoRequestStatus;
use App\Domains\Tenancy\Enums\DemoRequestStatus;
use App\Domains\Tenancy\Models\DemoRequest;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bandeja comercial de la consola: las solicitudes de demo del sitio público
 * y su seguimiento (nueva → contactada → cerrada). Filas de plataforma, sin
 * tenant.
 */
class DemoRequestController extends Controller
{
    private const int PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $requested = $request->query('status');
        $status = is_string($requested) ? DemoRequestStatus::tryFrom($requested) : null;

        $page = DemoRequest::query()
            ->with('handledBy:id,name')
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $counts = DemoRequest::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return Inertia::render('admin/demo-requests/index', [
            'requests' => collect($page->items())->map(fn (DemoRequest $demo) => [
                'id' => $demo->id,
                'name' => $demo->name,
                'company' => $demo->company,
                'email' => $demo->email,
                'phone' => $demo->phone,
                'fleetSize' => $demo->fleet_size,
                'message' => $demo->message,
                'status' => $demo->status->value,
                'handledBy' => $demo->handledBy?->name,
                'statusChangedAt' => $demo->status_changed_at?->toIso8601String(),
                'notified' => $demo->notified_at !== null,
                'createdAt' => $demo->created_at?->toIso8601String(),
            ])->values()->all(),
            'pagination' => [
                'page' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
            ],
            'counts' => collect(DemoRequestStatus::cases())
                ->mapWithKeys(fn (DemoRequestStatus $case) => [$case->value => (int) ($counts[$case->value] ?? 0)])
                ->all(),
            'filters' => ['status' => $status?->value],
        ]);
    }

    public function update(Request $request, DemoRequest $demoRequest, UpdateDemoRequestStatus $update, #[CurrentUser] User $actor): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(DemoRequestStatus::class)],
        ]);

        $update->execute($demoRequest, DemoRequestStatus::from($data['status']), $actor);

        $this->toast('Seguimiento actualizado.');

        return back();
    }
}
