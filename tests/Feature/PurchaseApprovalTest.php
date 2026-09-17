<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Membership;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseService;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PurchaseApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_threshold_routes_purchase_to_manager_or_owner_without_stock_posting(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $owner->assignRole('tenant-owner');
        $tenant = app(TenantProvisioningService::class)->provision('Approval Tenant', $owner);
        $owner->forceFill(['current_tenant_id' => $tenant->id])->save();
        $manager = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $manager->assignRole('manager');
        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $manager->id, 'role' => 'manager', 'status' => 'active']);
        foreach (['purchase_manager_approval_threshold' => 1000, 'purchase_owner_approval_threshold' => 5000] as $key => $value) {
            SystemSetting::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'key' => $key, 'value' => $value]);
        }
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Main', 'code' => 'MAIN',
        ]);
        $supplier = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'type' => 'supplier', 'name' => 'Supplier']);
        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Product', 'sku' => 'P']);
        $variant = ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'Default',
            'sku' => 'P-1', 'purchase_price' => 1000, 'sell_price' => 1500,
        ]);
        $service = app(PurchaseService::class);

        $managerPurchase = $service->createDraft($tenant->id, $warehouse->id, $supplier->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 2, 'unit_cost' => 1000,
        ]], $owner->id);
        $this->assertSame('pending_approval', $managerPurchase->status);
        $this->assertSame('manager', $managerPurchase->approval_level);
        try {
            $service->receive($managerPurchase->id, null, $tenant->id, $owner->id);
            $this->fail('Unapproved purchase must not be received.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $managerApproval = ApprovalRequest::withoutGlobalScopes()->where('subject_id', $managerPurchase->id)->firstOrFail();
        $this->actingAs($manager)->post(route('approvals.approve', $managerApproval))->assertRedirect();
        $this->assertSame('ordered', $managerPurchase->fresh()->status);
        $this->assertSame($manager->id, $managerPurchase->fresh()->approved_by);

        $ownerPurchase = $service->createDraft($tenant->id, $warehouse->id, $supplier->id, [[
            'product_variant_id' => $variant->id, 'quantity' => 6, 'unit_cost' => 1000,
        ]], $owner->id);
        $this->assertSame('owner', $ownerPurchase->approval_level);
        $ownerApproval = ApprovalRequest::withoutGlobalScopes()->where('subject_id', $ownerPurchase->id)->firstOrFail();
        $this->actingAs($manager)->post(route('approvals.approve', $ownerApproval))->assertForbidden();
        $this->actingAs($owner)->post(route('approvals.approve', $ownerApproval))->assertRedirect();
        $this->assertSame('ordered', $ownerPurchase->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'purchase.order.approved', 'subject_id' => $ownerPurchase->id]);
    }
}
