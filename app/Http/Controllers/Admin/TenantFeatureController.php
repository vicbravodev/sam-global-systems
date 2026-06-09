<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Tenancy\Actions\SetTenantFeature;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Super-admin manual feature overrides for a tenant: enable/disable a feature
 * and optionally adjust its limits (e.g. the asset cap).
 */
class TenantFeatureController extends Controller
{
    public function __construct(private readonly RecordAuditEntry $audit) {}

    public function update(
        Request $request,
        Team $team,
        string $featureKey,
        SetTenantFeature $setFeature,
    ): RedirectResponse {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'included_quantity' => ['nullable', 'integer', 'min:0'],
        ]);

        $limits = array_key_exists('included_quantity', $data)
            && $data['included_quantity'] !== null
                ? ['included_quantity' => (int) $data['included_quantity']]
                : null;

        $setFeature->execute($team, $featureKey, (bool) $data['enabled'], $limits);

        $user = $request->user();

        $this->audit->execute(
            actorType: AuditActorType::User,
            actorId: (int) $user->id,
            action: 'tenant.feature_updated',
            category: AuditCategory::Domain,
            entityType: Team::class,
            entityId: (int) $team->id,
            summary: "Feature {$featureKey} del tenant {$team->name} actualizada.",
            teamId: (int) $team->id,
            metadata: [
                'actor_email' => $user->email,
                'feature_key' => $featureKey,
                'enabled' => (bool) $data['enabled'],
                'limits' => $limits,
            ],
            signature: 'tenant.feature_updated:'.$team->id.':'.$featureKey.':'.Str::uuid()->toString(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return redirect()
            ->route('admin.tenants.show', $team)
            ->with('status', 'Feature actualizada.');
    }
}
