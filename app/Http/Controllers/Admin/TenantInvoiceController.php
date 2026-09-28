<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Tenancy\Enums\InvoiceStatus;
use App\Domains\Tenancy\Jobs\AggregateUsageJob;
use App\Domains\Tenancy\Jobs\GenerateInvoiceSnapshotJob;
use App\Domains\Tenancy\Models\InvoiceSnapshot;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\Rule;

/**
 * Super-admin invoice payment lifecycle (Roadmap B2): bank-transfer billing
 * means a human verifies the receipt and marks the invoice paid (or voids
 * it). The suspension lever for non-payment already exists on the
 * subscription controls.
 */
class TenantInvoiceController extends Controller
{
    public function __construct(private readonly RecordAuditEntry $audit) {}

    /**
     * Cierra un periodo a demanda (por defecto el mes anterior): recalcula
     * los contadores del periodo y genera la factura, igual que la corrida
     * mensual. Si la factura ya existe no se duplica.
     */
    public function generate(Request $request, Team $team): RedirectResponse
    {
        $data = $request->validate([
            'period' => ['nullable', 'string', Rule::date()->format('Y-m')],
        ]);

        $periodStart = isset($data['period'])
            ? now()->createFromFormat('Y-m-d', $data['period'].'-01')->startOfMonth()
            : now()->subMonthNoOverflow()->startOfMonth();

        $start = $periodStart->toDateString();
        $end = $periodStart->copy()->endOfMonth()->toDateString();

        $existing = TenantContext::for($team->id, fn () => InvoiceSnapshot::query()
            ->whereDate('period_start', $start)
            ->whereDate('period_end', $end)
            ->exists());

        if ($existing) {
            return back()->with('error', "Ya existe una factura para {$periodStart->format('Y-m')}.");
        }

        TenantContext::for($team->id, fn () => Bus::chain([
            new AggregateUsageJob($team->id, $start),
            new GenerateInvoiceSnapshotJob($team->id, $start, $end),
        ])->onQueue('billing')->dispatch());

        $this->audit->execute(
            actorType: AuditActorType::User,
            actorId: $request->user()?->id,
            action: 'tenant.invoice_generated',
            category: AuditCategory::Billing,
            entityType: Team::class,
            entityId: (int) $team->id,
            summary: "Factura de {$periodStart->format('Y-m')} generada a demanda para {$team->name}.",
            teamId: (int) $team->id,
            metadata: ['period_start' => $start, 'period_end' => $end],
        );

        return back()->with('success', "Factura de {$periodStart->format('Y-m')} en generación.");
    }

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
        // El operador abre la factura de OTRO tenant: se entra en el suyo,
        // que es lo que el scope global necesita para no chocar con el team
        // actual del admin. Ver §2.1.
        return TenantContext::for(
            $team->id,
            fn () => InvoiceSnapshot::query()->findOrFail($invoiceId),
        );
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
