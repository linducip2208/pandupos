<?php

namespace Tests\Feature;

use App\Models\BillingInvoice;
use App\Models\BillingTransaction;
use App\Models\User;
use App\Services\BillingService;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BillingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function provisionedTenant(): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko Billing', $owner);
        $owner->refresh();

        return [$tenant, $owner];
    }

    public function test_invoice_has_items_tax_currency_and_money_invariant(): void
    {
        [$tenant, $owner] = $this->provisionedTenant();
        $billing = app(BillingService::class);

        $invoice = $billing->createInvoice($tenant->id, null, 100000, 10000, [
            ['description' => 'Paket Growth - 1 bulan', 'quantity' => 1, 'unit_price' => 100000],
        ], 11000, 'idr');

        $this->assertSame('issued', $invoice->status);
        $this->assertSame('IDR', $invoice->currency);
        $this->assertSame('101000.00', (string) $invoice->total);
        $this->assertSame(1, $invoice->items()->count());
        $item = $invoice->items()->first();
        $this->assertSame('100000.00', (string) $item->unit_price);
        $this->assertSame('100000.00', (string) $item->amount);
        // Money invariant: total = subtotal - discount + tax
        $this->assertSame(round(100000 - 10000 + 11000, 2), (float) $invoice->total);
    }

    public function test_payment_attempt_then_webhook_marks_invoice_paid(): void
    {
        [$tenant, $owner] = $this->provisionedTenant();
        $billing = app(BillingService::class);

        $invoice = $billing->createInvoice($tenant->id, null, 50000, 0, [], 5000);
        $tx = $billing->recordAttempt($tenant->id, $invoice->id, 'operator-gateway', 'life-ref-1', 55000);
        $this->assertSame('pending', $tx->status);

        $result = $billing->handleWebhook('operator-gateway', 'life-ref-1', ['tenant_id' => $tenant->id, 'invoice_id' => $invoice->id, 'amount' => 55000], 'success');
        $this->assertSame('success', $result->status);
        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertNotNull($invoice->refresh()->paid_at);
    }

    public function test_reconciliation_closes_fully_paid_issued_invoices(): void
    {
        [$tenant, $owner] = $this->provisionedTenant();
        $billing = app(BillingService::class);

        $paidInvoice = $billing->createInvoice($tenant->id, null, 80000, 0, [], 0);
        BillingTransaction::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'billing_invoice_id' => $paidInvoice->id,
            'gateway' => 'gw', 'gateway_ref' => 'rec-ref-'.uniqid(), 'amount' => 80000, 'status' => 'success',
        ]);

        $openInvoice = $billing->createInvoice($tenant->id, null, 20000, 0, [], 0);

        $this->artisan('billing:reconcile')
            ->expectsOutputToContain('reconciled=1; already_paid=0');

        $this->assertSame('paid', $paidInvoice->refresh()->status);
        $this->assertSame('issued', $openInvoice->refresh()->status);

        // Re-running is idempotent: paid stays paid, no state churn.
        $this->artisan('billing:reconcile')->assertExitCode(0);
        $this->assertSame('paid', $paidInvoice->refresh()->status);
    }

    public function test_void_lifecycle_paid_is_immutable(): void
    {
        [$tenant, $owner] = $this->provisionedTenant();
        $billing = app(BillingService::class);

        $draft = BillingInvoice::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'invoice_no' => 'INV-DRAFT-'.uniqid(),
            'subtotal' => 10, 'discount' => 0, 'tax' => 0, 'total' => 10, 'status' => 'draft',
        ]);
        $billing->voidInvoice($draft->id, $tenant->id);
        $this->assertSame('void', $draft->refresh()->status);

        $paid = BillingInvoice::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'invoice_no' => 'INV-PAID-'.uniqid(),
            'subtotal' => 10, 'discount' => 0, 'tax' => 0, 'total' => 10, 'status' => 'paid', 'paid_at' => now(),
        ]);
        $this->expectException(HttpException::class);
        $billing->voidInvoice($paid->id, $tenant->id);
    }

    public function test_platform_invoice_pdf_download(): void
    {
        [$tenant, $owner] = $this->provisionedTenant();
        $invoice = app(BillingService::class)->createInvoice($tenant->id, null, 30000, 0, [], 0);
        $admin = User::factory()->create(['is_platform_admin' => true]);

        $pdf = $this->actingAs($admin)->get("/platform/billing/invoices/{$invoice->id}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith(
            'attachment; filename="billing-invoice-',
            $pdf->headers->get('Content-Disposition')
        );
        $this->assertStringStartsWith('%PDF', $pdf->baseResponse->getContent());

        $this->actingAs($owner)->get("/platform/billing/invoices/{$invoice->id}/pdf")
            ->assertForbidden();
    }
}
