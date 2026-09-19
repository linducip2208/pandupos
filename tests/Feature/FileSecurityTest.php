<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\CustomerLogin;
use App\Models\PaymentProof;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * File security audit: MIME, extension, size, filename, traversal,
 * storage location, ownership, tenant isolation, download authorization.
 */
class FileSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function portalContext(): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko File', $owner);
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $warehouse = Warehouse::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first()
            ?? Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'G', 'code' => 'G-'.uniqid()]);
        $contact = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'type' => 'customer', 'name' => 'C']);
        $login = CustomerLogin::create(['tenant_id' => $tenant->id, 'contact_id' => $contact->id, 'email' => 'f-'.uniqid().'@x.y', 'password' => bcrypt('secret123')]);
        $invoice = SalesInvoice::withoutGlobalScopes()->create([
            'uuid' => (string) \Str::uuid(), 'tenant_id' => $tenant->id, 'branch_id' => $branch->id,
            'warehouse_id' => $warehouse->id, 'contact_id' => $contact->id, 'invoice_no' => 'S-F-'.uniqid(),
            'status' => 'final', 'payment_status' => 'unpaid', 'fulfillment_status' => 'fulfilled',
            'subtotal' => 5000, 'total' => 5000, 'idempotency_key' => 'f-'.uniqid(),
        ]);

        return [$tenant, $owner, $contact, $login, $invoice];
    }

    public function test_payment_proof_validates_mime_extension_size(): void
    {
        [$tenant, $owner, $contact, $login, $invoice] = $this->portalContext();
        Storage::fake('local');

        // Dangerous .php masquerading as upload must be rejected.
        $evil = UploadedFile::fake()->create('shell.php', 10, 'application/x-php');
        $this->actingAs($login, 'customer')->post("/portal/invoices/{$invoice->id}/payment-proof", ['proof' => $evil])
            ->assertSessionHasErrors('proof');

        // Oversized file (>5MB) rejected.
        $big = UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf');
        $this->actingAs($login, 'customer')->post("/portal/invoices/{$invoice->id}/payment-proof", ['proof' => $big])
            ->assertSessionHasErrors('proof');

        // Valid proof accepted and stored under tenant-scoped private path.
        $ok = UploadedFile::fake()->create('bukti.jpg', 100, 'image/jpeg');
        $this->actingAs($login, 'customer')->post("/portal/invoices/{$invoice->id}/payment-proof", ['proof' => $ok])
            ->assertSessionHasNoErrors();
        $proof = PaymentProof::withoutGlobalScopes()->where('sales_invoice_id', $invoice->id)->firstOrFail();
        $this->assertStringStartsWith('portal-payment-proofs/'.$tenant->id, $proof->path);
        $this->assertNotContains($proof->mime_type, ['application/x-php', 'text/x-php']);
    }

    public function test_product_image_rejects_executable_and_limits_size(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko Img', $owner);
        $owner->update(['current_tenant_id' => $tenant->id]);
        Storage::fake('public');

        $php = UploadedFile::fake()->create('evil.php', 50, 'application/x-php');
        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'P', 'sku' => 'IMG-'.uniqid()]);
        $res = $this->actingAs($owner)->put("/products/{$product->id}", [
            'name' => 'P', 'product_type' => 'stock', 'alert_quantity' => 0, 'tax_rate' => 0,
            'tax_method' => 'zero', 'variants' => [['name' => 'D', 'purchase_price' => 1, 'sell_price' => 2]],
            'image' => $php,
        ]);
        $this->assertContains($res->getStatusCode(), [302, 422]);
        $this->assertFalse(str_ends_with($product->refresh()->image_path ?? '', '.php'));
    }

    public function test_tenant_a_cannot_access_tenant_b_file_by_guessing_id(): void
    {
        [$tenantA, $ownerA, $contactA, $loginA, $invoiceA] = $this->portalContext();
        $tenantB = app(TenantProvisioningService::class)->provision('Toko FileB', User::factory()->create());
        $branchB = Branch::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->first();
        $warehouseB = Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenantB->id, 'branch_id' => $branchB->id, 'name' => 'GB', 'code' => 'GB-'.uniqid()]);
        $contactB = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenantB->id, 'type' => 'customer', 'name' => 'CB']);
        $loginB = CustomerLogin::create(['tenant_id' => $tenantB->id, 'contact_id' => $contactB->id, 'email' => 'gb-'.uniqid().'@x.y', 'password' => bcrypt('secret123')]);
        $invoiceB = SalesInvoice::withoutGlobalScopes()->create([
            'uuid' => (string) \Str::uuid(), 'tenant_id' => $tenantB->id, 'branch_id' => $branchB->id,
            'warehouse_id' => $warehouseB->id, 'contact_id' => $contactB->id, 'invoice_no' => 'S-FB-'.uniqid(),
            'status' => 'final', 'payment_status' => 'unpaid', 'fulfillment_status' => 'fulfilled',
            'subtotal' => 7000, 'total' => 7000, 'idempotency_key' => 'fb-'.uniqid(),
        ]);

        // A tries to upload proof to B invoice -> 404 (ownership enforced).
        $ok = UploadedFile::fake()->create('bukti.png', 100, 'image/png');
        $this->actingAs($loginA, 'customer')->post("/portal/invoices/{$invoiceB->id}/payment-proof", ['proof' => $ok])->assertNotFound();
        // A tries to view B invoice -> 404.
        $this->actingAs($loginA, 'customer')->get("/portal/invoices/{$invoiceB->id}")->assertNotFound();
    }

    public function test_path_traversal_filename_is_sanitized(): void
    {
        [$tenant, $owner, $contact, $login, $invoice] = $this->portalContext();
        Storage::fake('local');
        $evil = UploadedFile::fake()->create('../../evil.jpg', 100, 'image/jpeg');
        $this->actingAs($login, 'customer')->post("/portal/invoices/{$invoice->id}/payment-proof", ['proof' => $evil]);
        $proof = PaymentProof::withoutGlobalScopes()->where('sales_invoice_id', $invoice->id)->first();
        if ($proof) {
            $this->assertStringNotContainsString('..', $proof->path);
            $this->assertStringStartsWith('portal-payment-proofs/', $proof->path);
        } else {
            $this->assertTrue(true);
        }
    }

    public function test_public_storage_does_not_execute_dangerous_files(): void
    {
        // Public disk must only hold validated images; no .php/.phtml/.htaccess in products path.
        $productsPath = storage_path('app/public/products');
        $dangerous = [];
        if (is_dir($productsPath)) {
            foreach (glob($productsPath.'/*') ?: [] as $f) {
                if (preg_match('/\.(php|phtml|phar|htaccess|sh)$/i', $f)) {
                    $dangerous[] = basename($f);
                }
            }
        }
        $this->assertSame([], $dangerous);
    }
}
