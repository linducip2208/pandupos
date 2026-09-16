<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\CustomerLogin;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_login_view_own_invoice_and_download_pdf(): void
    {
        [$customer, $ownInvoice] = $this->portalFixture();

        $this->post(route('portal.login.attempt'), ['email' => $customer->email, 'password' => 'password'])
            ->assertRedirect(route('portal.dashboard'));

        $this->actingAs($customer, 'customer')->get(route('portal.dashboard'))->assertOk()->assertSee('Pelanggan Satu');
        $this->actingAs($customer, 'customer')->get(route('portal.invoices.show', $ownInvoice->id))->assertOk()->assertSee('INV-PORTAL-1');
        $this->actingAs($customer, 'customer')->get(route('portal.invoices.pdf', $ownInvoice->id))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_customer_cannot_view_another_customers_invoice(): void
    {
        [$customer, , $otherInvoice] = $this->portalFixture();

        $this->actingAs($customer, 'customer')->get(route('portal.invoices.show', $otherInvoice->id))->assertNotFound();
        $this->actingAs($customer, 'customer')->get(route('portal.orders.show', $otherInvoice->id))->assertNotFound();
    }

    public function test_customer_can_upload_payment_proof_only_for_owned_invoice(): void
    {
        Storage::fake('local');
        [$customer, $ownInvoice, $otherInvoice] = $this->portalFixture();

        $this->actingAs($customer, 'customer')->post(route('portal.payment-proofs.store', $ownInvoice->id), [
            'proof' => UploadedFile::fake()->image('transfer.jpg'),
            'notes' => 'Transfer bank',
        ])->assertSessionHas('status');

        $proof = $customer->paymentProofs()->firstOrFail();
        Storage::disk('local')->assertExists($proof->path);
        $this->assertDatabaseHas('payment_proofs', ['sales_invoice_id' => $ownInvoice->id, 'status' => 'pending']);

        $this->actingAs($customer, 'customer')->post(route('portal.payment-proofs.store', $otherInvoice->id), [
            'proof' => UploadedFile::fake()->image('invalid.jpg'),
        ])->assertNotFound();
    }

    private function portalFixture(): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Tenant Portal', $owner);
        $branch = $tenant->branches()->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Gudang', 'code' => 'PORTAL']);
        $contact = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'type' => 'customer', 'name' => 'Pelanggan Satu', 'email' => 'customer-one@example.test']);
        $other = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'type' => 'customer', 'name' => 'Pelanggan Dua', 'email' => 'customer-two@example.test']);
        $customer = CustomerLogin::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'contact_id' => $contact->id, 'email' => $contact->email, 'password' => 'password']);

        $ownInvoice = $this->invoice($tenant->id, $branch->id, $warehouse->id, $contact->id, 'INV-PORTAL-1');
        $otherInvoice = $this->invoice($tenant->id, $branch->id, $warehouse->id, $other->id, 'INV-PORTAL-2');

        return [$customer, $ownInvoice, $otherInvoice];
    }

    private function invoice(int $tenantId, int $branchId, int $warehouseId, int $contactId, string $number): SalesInvoice
    {
        return SalesInvoice::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'branch_id' => $branchId,
            'warehouse_id' => $warehouseId, 'contact_id' => $contactId, 'invoice_no' => $number,
            'status' => 'final', 'payment_status' => 'unpaid', 'fulfillment_status' => 'unfulfilled',
            'subtotal' => 125000, 'discount' => 0, 'tax' => 0, 'total' => 125000,
        ]);
    }
}
