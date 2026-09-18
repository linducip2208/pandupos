<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesQuotation;
use App\Models\User;
use App\Services\SalesDocumentService;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SalesCommercialDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_quotation_lifecycle_and_proforma_conversion_are_non_posting_and_immutable(): void
    {
        $this->seed(PlatformSeeder::class);
        $user = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Sales Docs', $user);
        $user->forceFill(['current_tenant_id' => $tenant->id])->save();
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $customer = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'type' => 'customer', 'name' => 'Customer']);
        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Item', 'sku' => 'ITEM']);
        $variant = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'Default', 'sku' => 'ITEM-1', 'sell_price' => 12500]);
        $service = app(SalesDocumentService::class);

        $quotation = $service->createQuotation($tenant->id, [
            'branch_id' => $branch->id, 'contact_id' => $customer->id, 'quotation_date' => today()->toDateString(),
            'valid_until' => today()->addDays(14)->toDateString(), 'discount' => 1000, 'tax' => 1100,
            'lines' => [['product_variant_id' => $variant->id, 'quantity' => 2, 'unit_price' => 10000, 'discount' => 500]],
        ], $user->id);
        $this->assertSame('draft', $quotation->status);
        $this->assertSame('19600.00', $quotation->total);
        $service->transition($quotation, 'sent', $user->id);
        $service->transition($quotation->fresh(), 'accepted', $user->id);
        $proforma = $service->convertToProforma($quotation->fresh(), $user->id, today()->addDays(7)->toDateString());

        $this->assertSame('accepted', $quotation->fresh()->status);
        $this->assertSame('issued', $proforma->status);
        $this->assertSame($quotation->id, $proforma->sales_quotation_id);
        $this->assertSame('19600.00', $proforma->total);
        $this->assertDatabaseCount('inventory_balances', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'proforma.created_from_quotation', 'subject_id' => $proforma->id]);
    }

    public function test_invalid_transition_and_cross_tenant_reference_are_rejected(): void
    {
        $this->seed(PlatformSeeder::class);
        $user = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Tenant A', $user);
        $otherUser = User::factory()->create();
        $other = app(TenantProvisioningService::class)->provision('Tenant B', $otherUser);
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $customer = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'type' => 'customer', 'name' => 'Customer']);
        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'name' => 'Other', 'sku' => 'OTHER']);
        $variant = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'product_id' => $product->id, 'name' => 'Default', 'sku' => 'OTHER-1']);

        $this->expectException(HttpException::class);
        app(SalesDocumentService::class)->createQuotation($tenant->id, [
            'branch_id' => $branch->id, 'contact_id' => $customer->id,
            'lines' => [['product_variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 1]],
        ], $user->id);
    }

    public function test_authorized_api_creates_quotation_and_rejects_duplicate_conversion(): void
    {
        $this->seed(PlatformSeeder::class);
        $user = User::factory()->create();
        $user->givePermissionTo(['sales.view', 'sales.create']);
        $tenant = app(TenantProvisioningService::class)->provision('API Sales Docs', $user);
        $user->forceFill(['current_tenant_id' => $tenant->id])->save();
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $customer = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'type' => 'customer', 'name' => 'API Customer']);
        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'API Item', 'sku' => 'API']);
        $variant = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'Default', 'sku' => 'API-1']);
        Sanctum::actingAs($user);

        $quotationId = $this->postJson('/api/v1/sales-documents/quotations', [
            'branch_id' => $branch->id, 'contact_id' => $customer->id,
            'lines' => [['product_variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 25000]],
        ])->assertCreated()->assertJsonPath('status', 'draft')->json('id');
        $this->postJson("/api/v1/sales-documents/quotations/{$quotationId}/transition", ['status' => 'sent'])->assertOk();
        $this->postJson("/api/v1/sales-documents/quotations/{$quotationId}/proforma", [])->assertCreated();
        $this->postJson("/api/v1/sales-documents/quotations/{$quotationId}/proforma", [])->assertStatus(422);
    }

    public function test_tenant_workspace_creates_and_prints_quotation_without_cross_tenant_access(): void
    {
        $this->seed(PlatformSeeder::class);
        $user = User::factory()->create();
        $user->givePermissionTo(['sales.view', 'sales.create']);
        $tenant = app(TenantProvisioningService::class)->provision('Quotation Workspace', $user);
        $user->forceFill(['current_tenant_id' => $tenant->id])->save();
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $customer = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'type' => 'customer', 'name' => 'Workspace Customer']);
        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Workspace Item', 'sku' => 'QW']);
        $variant = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'Default', 'sku' => 'QW-1']);

        $this->actingAs($user)->get(route('sales-documents.index'))->assertOk()->assertSeeText('Buat quotation');
        $this->post(route('sales-documents.quotations.store'), [
            'branch_id' => $branch->id, 'contact_id' => $customer->id, 'quotation_date' => today()->toDateString(),
            'product_variant_id' => $variant->id, 'quantity' => 2, 'unit_price' => 12500,
        ])->assertRedirect()->assertSessionHas('status');
        $quotation = SalesQuotation::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->actingAs($user)->get(route('sales-documents.quotations.print', $quotation))->assertOk()->assertSeeText('QUOTATION')->assertSeeText($quotation->quotation_no);

        $foreignUser = User::factory()->create();
        $foreign = app(TenantProvisioningService::class)->provision('Foreign Quotation', $foreignUser);
        $foreignBranch = Branch::withoutGlobalScopes()->where('tenant_id', $foreign->id)->firstOrFail();
        $foreignCustomer = Contact::withoutGlobalScopes()->create(['tenant_id' => $foreign->id, 'type' => 'customer', 'name' => 'Foreign Customer']);
        $foreignProduct = Product::withoutGlobalScopes()->create(['tenant_id' => $foreign->id, 'name' => 'Foreign Item', 'sku' => 'FQ']);
        $foreignVariant = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $foreign->id, 'product_id' => $foreignProduct->id, 'name' => 'Default', 'sku' => 'FQ-1']);
        $foreignQuotation = app(SalesDocumentService::class)->createQuotation($foreign->id, [
            'branch_id' => $foreignBranch->id, 'contact_id' => $foreignCustomer->id,
            'lines' => [['product_variant_id' => $foreignVariant->id, 'quantity' => 1, 'unit_price' => 10]],
        ], $foreignUser->id);

        $this->actingAs($user)->get(route('sales-documents.quotations.print', $foreignQuotation))->assertNotFound();
    }
}
