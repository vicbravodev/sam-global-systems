<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Tenancy\Actions\UpdateTenantBillingTerms;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Términos comerciales por tenant (super-admin): precio por tracto, moneda,
 * tope contratado, mínimo, uso justo de IA, margen Twilio y tipo de cambio.
 * Un campo vacío vuelve al default de plataforma (config/billing.php).
 */
class TenantBillingTermsController extends Controller
{
    public function __construct(private readonly RecordAuditEntry $audit) {}

    public function update(Request $request, Team $team, UpdateTenantBillingTerms $updateTerms, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'currency' => ['nullable', 'string', 'size:3', 'alpha'],
            'included_assets' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'min_billable_assets' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'ai_fair_use_per_asset' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'ai_overage_unit_price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'messaging_markup_percent' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'fx_usd_rate' => ['nullable', 'numeric', 'min:0.0001', 'max:100000'],
            'volume_tiers' => ['nullable', 'array', 'max:20'],
            'volume_tiers.*.from' => ['required_with:volume_tiers', 'integer', 'min:0'],
            'volume_tiers.*.to' => ['nullable', 'integer', 'min:0'],
            'volume_tiers.*.unit_price' => ['required_with:volume_tiers', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $terms = $updateTerms->execute($team, $data);

        $this->audit->execute(
            actorType: AuditActorType::User,
            actorId: (int) $user->id,
            action: 'tenant.billing_terms_updated',
            category: AuditCategory::Billing,
            entityType: Team::class,
            entityId: (int) $team->id,
            summary: "Términos de facturación del tenant {$team->name} actualizados.",
            teamId: (int) $team->id,
            metadata: ['actor_email' => $user->email, 'terms' => $terms->only([
                'unit_price', 'currency', 'included_assets', 'min_billable_assets',
                'ai_fair_use_per_asset', 'ai_overage_unit_price', 'messaging_markup_percent',
                'fx_usd_rate', 'volume_tiers_json',
            ])],
            signature: 'tenant.billing_terms_updated:'.$team->id.':'.Str::uuid()->toString(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return redirect()
            ->route('admin.tenants.show', $team)
            ->with('status', 'Términos de facturación actualizados.');
    }
}
