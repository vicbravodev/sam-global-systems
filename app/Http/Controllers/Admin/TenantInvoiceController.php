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
use App\Support\SystemLog;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
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

        // `period` ya validó como Y-m: parse() nunca devuelve null (lanza si
        // la fecha fuera inválida), a diferencia de createFromFormat().
        $periodStart = isset($data['period'])
            ? CarbonImmutable::parse($data['period'].'-01')->startOfMonth()
            : now()->subMonthNoOverflow()->startOfMonth();

        $start = $periodStart->toDateString();
        $end = $periodStart->copy()->endOfMonth()->toDateString();

        $existing = TenantContext::for($team->id, fn () => InvoiceSnapshot::query()
            ->whereDate('period_start', $start)
            ->whereDate('period_end', $end)
            ->exists());

        $logInput = [
            'team_id' => $team->id,
            'actor_user_id' => $request->user()?->id,
            'period_start' => $start,
            'period_end' => $end,
        ];

        if ($existing) {
            TenantContext::for($team->id, fn () => SystemLog::skipped('billing.invoice.already_exists',
                reason: 'period_already_invoiced',
                input: $logInput + ['stage' => 'admin_request'],
            ));

            $this->toast("Ya existe una factura para {$periodStart->format('Y-m')}.", 'error');

            return back();
        }

        TenantContext::for($team->id, fn () => Bus::chain([
            new AggregateUsageJob($team->id, $start),
            new GenerateInvoiceSnapshotJob($team->id, $start, $end),
        ])->onQueue('billing')->dispatch());

        // Lo pedido, no lo que la cadena hará: la factura la narra
        // GenerateInvoiceSnapshotJob (billing.invoice.generated / already_exists).
        TenantContext::for($team->id, fn () => SystemLog::ok('billing.invoice.generation_requested',
            input: $logInput,
            result: ['chain_requested' => true],
        ));

        $this->audit->execute(
            actorType: AuditActorType::User,
            actorId: $request->user()?->id,
            action: 'tenant.invoice_generated',
            category: AuditCategory::Billing,
            entityType: Team::class,
            entityId: $team->id,
            summary: "Factura de {$periodStart->format('Y-m')} generada a demanda para {$team->name}.",
            teamId: $team->id,
            metadata: ['period_start' => $start, 'period_end' => $end],
        );

        $this->toast("Factura de {$periodStart->format('Y-m')} en generación.");

        return back();
    }

    public function markPaid(Request $request, Team $team, int $invoice): RedirectResponse
    {
        // Transacción + bloqueo de fila: dos clics simultáneos no registran
        // dos cambios de estado (y el log afterCommit sale con el commit real).
        DB::transaction(function () use ($request, $team, $invoice): void {
            $invoice = $this->invoiceFor($team, $invoice, lock: true);

            abort_if($invoice->status === InvoiceStatus::Void, 422, 'Una factura anulada no se puede marcar como pagada.');
            abort_if($invoice->status === InvoiceStatus::Paid, 422, 'La factura ya está pagada.');

            $from = $invoice->status->value;

            $invoice->forceFill([
                'status' => InvoiceStatus::Paid,
                'paid_at' => now(),
            ])->save();

            $this->logStatusChange($request, $team, $invoice, $from);

            $this->record($request, $team, $invoice, 'tenant.invoice_paid',
                "Factura #{$invoice->id} del cliente {$team->name} marcada como pagada.");
        });

        $this->toast('Factura marcada como pagada.');

        return back();
    }

    public function void(Request $request, Team $team, int $invoice): RedirectResponse
    {
        DB::transaction(function () use ($request, $team, $invoice): void {
            $invoice = $this->invoiceFor($team, $invoice, lock: true);

            abort_if($invoice->status === InvoiceStatus::Paid, 422, 'Una factura pagada no se anula.');
            abort_if($invoice->status === InvoiceStatus::Void, 422, 'La factura ya está anulada.');

            $from = $invoice->status->value;

            $invoice->forceFill(['status' => InvoiceStatus::Void])->save();

            $this->logStatusChange($request, $team, $invoice, $from);

            $this->record($request, $team, $invoice, 'tenant.invoice_voided',
                "Factura #{$invoice->id} del cliente {$team->name} anulada.");
        });

        $this->toast('Factura anulada.');

        return back();
    }

    /**
     * Explicit lookup: implicit binding would apply the BelongsToTenant scope
     * with the ADMIN's own current team and 404 every foreign invoice.
     */
    private function invoiceFor(Team $team, int $invoiceId, bool $lock = false): InvoiceSnapshot
    {
        // El operador abre la factura de OTRO tenant: se entra en el suyo,
        // que es lo que el scope global necesita para no chocar con el team
        // actual del admin. Ver §2.1.
        return TenantContext::for(
            $team->id,
            fn () => InvoiceSnapshot::query()->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($invoiceId),
        );
    }

    /**
     * Hecho persistido: se registra tras el commit con los valores capturados
     * al escribir. Nunca la nota de pago, el comprobante ni el nombre del tenant.
     */
    private function logStatusChange(Request $request, Team $team, InvoiceSnapshot $invoice, string $from): void
    {
        $input = [
            'team_id' => $team->id,
            'invoice_id' => $invoice->id,
            'actor_user_id' => $request->user()?->id,
        ];
        $calc = [
            'from_status' => $from,
            'to_status' => $invoice->status->value,
            'receipt_present' => $invoice->payment_receipt_file_object_id !== null,
        ];

        DB::afterCommit(fn () => TenantContext::for($input['team_id'], fn () => SystemLog::ok('billing.invoice.status_changed',
            input: $input,
            calc: $calc,
        )));
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
