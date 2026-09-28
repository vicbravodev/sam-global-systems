<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Tenancy\Enums\InvoiceStatus;
use App\Domains\Tenancy\Models\InvoiceSnapshot;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToWriteFile;
use Mockery;
use Tests\TestCase;

/**
 * Roadmap B2: bank-transfer billing — the tenant uploads the payment receipt
 * and the super-admin marks the invoice paid (or voids it), with audit.
 */
class InvoicePaymentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);

        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;
    }

    public function test_tenant_can_upload_payment_receipt(): void
    {
        Storage::fake('rustfs');

        $invoice = InvoiceSnapshot::factory()->create([
            'team_id' => $this->team->id,
            'status' => InvoiceStatus::Invoiced,
        ]);

        $response = $this->actingAs($this->user)->post(
            route('billing.invoices.receipt', [
                'current_team' => $this->team->slug,
                'invoice' => $invoice->id,
            ]),
            [
                'receipt' => UploadedFile::fake()->create('transferencia.pdf', 120, 'application/pdf'),
                'note' => 'SPEI 1234',
            ],
        );

        $response->assertCreated();

        $invoice->refresh();
        $this->assertNotNull($invoice->payment_receipt_file_object_id);
        $this->assertSame('SPEI 1234', $invoice->payment_note);

        $this->assertDatabaseHas('file_objects', [
            'team_id' => $this->team->id,
            'category' => 'payment_receipt',
        ]);
    }

    public function test_receipt_is_rejected_on_paid_invoice(): void
    {
        $invoice = InvoiceSnapshot::factory()->create([
            'team_id' => $this->team->id,
            'status' => InvoiceStatus::Paid,
        ]);

        $this->actingAs($this->user)->post(
            route('billing.invoices.receipt', [
                'current_team' => $this->team->slug,
                'invoice' => $invoice->id,
            ]),
            ['receipt' => UploadedFile::fake()->create('t.pdf', 10, 'application/pdf')],
        )->assertStatus(422);
    }

    public function test_receipt_is_rejected_for_other_tenant_invoice(): void
    {
        $foreign = InvoiceSnapshot::factory()->create([
            'team_id' => User::factory()->create()->currentTeam->id,
        ]);

        $this->actingAs($this->user)->post(
            route('billing.invoices.receipt', [
                'current_team' => $this->team->slug,
                'invoice' => $foreign->id,
            ]),
            ['receipt' => UploadedFile::fake()->create('t.pdf', 10, 'application/pdf')],
        )->assertNotFound();
    }

    /**
     * UI audit P0-4: storage down → readable 503 on the `receipt` field,
     * reported to ops, and nothing half-persisted (no FileObject, no link).
     */
    public function test_receipt_upload_returns_readable_error_when_storage_fails(): void
    {
        $failing = Mockery::mock(Filesystem::class);
        $failing->shouldReceive('put')->andThrow(UnableToWriteFile::atLocation('billing/x.pdf', 'connection refused'));
        Storage::set('rustfs', $failing);
        Log::spy();

        $invoice = InvoiceSnapshot::factory()->create([
            'team_id' => $this->team->id,
            'status' => InvoiceStatus::Invoiced,
        ]);

        $this->actingAs($this->user)->postJson(
            route('billing.invoices.receipt', [
                'current_team' => $this->team->slug,
                'invoice' => $invoice->id,
            ]),
            ['receipt' => UploadedFile::fake()->create('transferencia.pdf', 120, 'application/pdf')],
        )
            ->assertStatus(503)
            ->assertJsonPath('errors.receipt.0', fn (string $message) => str_contains($message, 'No se pudo subir el comprobante'));

        $this->assertNull($invoice->refresh()->payment_receipt_file_object_id);
        $this->assertDatabaseMissing('file_objects', ['team_id' => $this->team->id, 'category' => 'payment_receipt']);

        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message, array $context) => str_contains($message, 'invoice_receipt_upload')
                && $context['invoice_id'] === $invoice->id,
        )->once();
    }

    public function test_super_admin_marks_invoice_paid_with_audit(): void
    {
        $admin = User::factory()->create(['global_role' => 'super_admin']);

        $invoice = InvoiceSnapshot::factory()->create([
            'team_id' => $this->team->id,
            'status' => InvoiceStatus::Invoiced,
        ]);

        $response = $this->actingAs($admin)->post(
            route('admin.tenants.invoices.mark-paid', [
                'team' => $this->team->slug,
                'invoice' => $invoice->id,
            ]),
        );

        $response->assertRedirect();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertNotNull($invoice->paid_at);

        $this->assertSame(1, AuditLog::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->where('action', 'tenant.invoice_paid')
            ->count());
    }

    public function test_paid_invoice_cannot_be_voided(): void
    {
        $admin = User::factory()->create(['global_role' => 'super_admin']);

        $invoice = InvoiceSnapshot::factory()->create([
            'team_id' => $this->team->id,
            'status' => InvoiceStatus::Paid,
        ]);

        $this->actingAs($admin)->post(
            route('admin.tenants.invoices.void', [
                'team' => $this->team->slug,
                'invoice' => $invoice->id,
            ]),
        )->assertStatus(422);
    }

    public function test_void_invoice_cannot_be_marked_paid_nor_voided_again(): void
    {
        $admin = User::factory()->create(['global_role' => 'super_admin']);

        $invoice = InvoiceSnapshot::factory()->create([
            'team_id' => $this->team->id,
            'status' => InvoiceStatus::Void,
        ]);

        foreach (['admin.tenants.invoices.mark-paid', 'admin.tenants.invoices.void'] as $route) {
            $this->actingAs($admin)->post(route($route, [
                'team' => $this->team->slug,
                'invoice' => $invoice->id,
            ]))->assertStatus(422);
        }

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Void, $invoice->status);
        $this->assertNull($invoice->paid_at);
    }

    public function test_receipt_is_rejected_on_void_or_running_draft_invoice(): void
    {
        Storage::fake('rustfs');

        $void = InvoiceSnapshot::factory()->create([
            'team_id' => $this->team->id,
            'status' => InvoiceStatus::Void,
        ]);
        $runningDraft = InvoiceSnapshot::factory()->create([
            'team_id' => $this->team->id,
            'status' => InvoiceStatus::Draft,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
        ]);

        foreach ([$void, $runningDraft] as $invoice) {
            $this->actingAs($this->user)->post(
                route('billing.invoices.receipt', [
                    'current_team' => $this->team->slug,
                    'invoice' => $invoice->id,
                ]),
                ['receipt' => UploadedFile::fake()->create('t.pdf', 10, 'application/pdf')],
            )->assertStatus(422);

            $this->assertNull($invoice->refresh()->payment_receipt_file_object_id);
        }
    }

    public function test_receipt_is_accepted_on_draft_of_a_closed_period(): void
    {
        Storage::fake('rustfs');

        $invoice = InvoiceSnapshot::factory()->create([
            'team_id' => $this->team->id,
            'status' => InvoiceStatus::Draft,
            'period_start' => now()->subMonth()->startOfMonth()->toDateString(),
            'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
        ]);

        $this->actingAs($this->user)->post(
            route('billing.invoices.receipt', [
                'current_team' => $this->team->slug,
                'invoice' => $invoice->id,
            ]),
            ['receipt' => UploadedFile::fake()->create('t.pdf', 10, 'application/pdf')],
        )->assertCreated();
    }

    public function test_regular_user_cannot_use_admin_invoice_actions(): void
    {
        $invoice = InvoiceSnapshot::factory()->create(['team_id' => $this->team->id]);

        $this->actingAs($this->user)->post(
            route('admin.tenants.invoices.mark-paid', [
                'team' => $this->team->slug,
                'invoice' => $invoice->id,
            ]),
        )->assertForbidden();
    }
}
