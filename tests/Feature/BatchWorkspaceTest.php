<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BatchWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_can_receive_a_batch_with_audit_and_ledger_stock(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Batch UI Tenant', $owner);
        $owner->forceFill(['current_tenant_id' => $tenant->id])->save();
        $warehouse = Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'branch_id' => Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('id'), 'name' => 'Pusat', 'code' => 'PST']);
        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Susu', 'product_type' => 'stock']);
        $variant = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => '1L', 'sku' => 'SUSU-1L']);

        $this->actingAs($owner)->get(route('batches.index'))->assertOk()->assertSeeText('Terima batch');
        $this->actingAs($owner)->post(route('batches.receive'), [
            'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'batch_number' => 'LOT-001',
            'quantity' => 12, 'unit_cost' => 15000, 'expires_at' => today()->addDays(20)->toDateString(),
        ])->assertRedirect();

        $batch = InventoryBatch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertDatabaseHas('stock_movements', ['tenant_id' => $tenant->id, 'inventory_batch_id' => $batch->id, 'movement_type' => 'in', 'quantity' => 12]);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'inventory.batch.received', 'subject_id' => $batch->id]);
    }

    public function test_batch_workspace_hides_another_tenants_batches(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Owner Tenant', $owner);
        $foreign = app(TenantProvisioningService::class)->provision('Foreign Tenant', User::factory()->create());
        $owner->forceFill(['current_tenant_id' => $tenant->id])->save();
        $foreignWarehouse = Warehouse::withoutGlobalScopes()->create(['tenant_id' => $foreign->id, 'branch_id' => Branch::withoutGlobalScopes()->where('tenant_id', $foreign->id)->value('id'), 'name' => 'Foreign', 'code' => 'FOREIGN']);
        $foreignProduct = Product::withoutGlobalScopes()->create(['tenant_id' => $foreign->id, 'name' => 'Foreign Product', 'product_type' => 'stock']);
        $foreignVariant = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $foreign->id, 'product_id' => $foreignProduct->id, 'name' => 'Default', 'sku' => 'FOREIGN']);
        InventoryBatch::withoutGlobalScopes()->create(['tenant_id' => $foreign->id, 'product_variant_id' => $foreignVariant->id, 'warehouse_id' => $foreignWarehouse->id, 'batch_number' => 'FOREIGN']);

        $this->actingAs($owner)->get(route('batches.index'))->assertOk()->assertDontSeeText('FOREIGN');
    }
}
