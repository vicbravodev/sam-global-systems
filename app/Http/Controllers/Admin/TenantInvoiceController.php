<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Tenancy\Enums\InvoiceStatus;
use App\Domains\Tenancy\Models\InvoiceSnapshot;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Super-admin invoice payment lifecycle (Roadmap B2): bank-transfer billing
 * means a human verifies the receipt and marks the invoice paid (or voids
 * it). The suspension lever for non-payment already exists on the
 * subscription controls.
 */
class TenantInvoiceController extends Controller
{
    public function __construct(private readonly RecordAuditEntry $audit) {}

    public function markPaid(Request $request, Team $team, int $invoice): RedirectResponse
    {
        $invoice = $this->invoiceFor($team, $invoice);

        $invoice->forceFill([
            'status' => InvoiceStatus::Paid,
            'paid_at' => now(),
        ])->save();

        $this->record($request, $team, $invoice, 'tenant.invoice_paid',
            "Factura #{$invoice->id} del tenant {$team->name} marcada como pagada.");

        return back()->with('success', 'Factura marcada como pagada.');
    }

    public function void(Request $request, Team $team, int $invoice): RedirectResponse
    {
        $invoice = $this->invoiceFor($team, $invoice);

        abort_if($invoice->status === InvoiceStatus::Paid, 422, 'Una factura pagada no se anula.');

        $invoice->forceFill(['status' => InvoiceStatus::Void])->save();

        $this->record($request, $team, $invoice, 'tenant.invoice_voided',
            "Factura #{$invoice->id} del tenant {$team->name} anulada.");

        return back()->with('success', 'Factura anulada.');
    }

    /**
     * Explicit lookup: implicit binding would apply the BelongsToTenant scope
     * with the ADMIN's own current team and 404 every foreign invoice.
     */
    private function invoiceFor(Team $team, int $invoiceId): InvoiceSnapshot
    {
        return InvoiceSnapshot::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->findOrFail($invoiceId);
    }

    private function record(Request $request, Team $team, InvoiceSnapshot $invoice, string $action, string $summary): void
    {
        $this->audit->execute(
            actorType: AuditActorType::User,
            actorId: $request->user()?->id,
            action: $action,
            category: AuditCategory::Billing,
            entityType: 'invoice_snapshot',
            entityId: $invoice->id,
            summary: $summary,
            teamId: $team->id,
            metadata: ['status' => $invoice->status?->value],
        );
    }
}
