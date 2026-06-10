<?php

namespace App\Http\Controllers\Tenancy;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Tenancy\Enums\InvoiceStatus;
use App\Domains\Tenancy\Models\FileObject;
use App\Domains\Tenancy\Models\InvoiceSnapshot;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Tenant uploads the bank-transfer receipt for an invoice (Roadmap B2). The
 * super-admin verifies it and marks the invoice paid from the console.
 */
class InvoiceReceiptController extends Controller
{
    public function store(
        Request $request,
        Team $current_team,
        int $invoice,
        AuthorizeAction $authorizeAction,
    ): JsonResponse {
        // Explicit lookup — implicit binding + BelongsToTenant can disagree
        // with the route team when the user belongs to several teams.
        $invoice = InvoiceSnapshot::withoutGlobalScopes()
            ->where('team_id', $current_team->id)
            ->findOrFail($invoice);

        abort_unless(
            $request->user() !== null
                && $authorizeAction->execute($request->user(), 'tenancy.billing.manage', $current_team),
            403,
        );

        abort_if($invoice->status === InvoiceStatus::Paid, 422, 'La factura ya está pagada.');

        $validated = $request->validate([
            'receipt' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:4096'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('receipt');
        $key = "billing/{$current_team->id}/receipts/{$invoice->id}-".$file->hashName();

        Storage::disk('rustfs')->put($key, (string) $file->get());

        $fileObject = FileObject::query()->create([
            'team_id' => $current_team->id,
            'bucket' => (string) config('filesystems.disks.rustfs.bucket', 'sam'),
            'object_key' => $key,
            'original_filename' => $file->getClientOriginalName(),
            'size_bytes' => $file->getSize(),
            'content_type' => $file->getMimeType(),
            'visibility' => 'private',
            'category' => 'payment_receipt',
        ]);

        $invoice->forceFill([
            'payment_receipt_file_object_id' => $fileObject->id,
            'payment_note' => $validated['note'] ?? null,
        ])->save();

        return response()->json(['data' => [
            'invoiceId' => (int) $invoice->id,
            'receiptKey' => $key,
        ]], 201);
    }
}
