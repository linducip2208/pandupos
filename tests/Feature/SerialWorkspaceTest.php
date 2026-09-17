<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerialWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_can_receive_serial_with_audit_and_ledger_history(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Serial UI Tenant', $owner);
        $owner->forceFill(['current_tenant_id' => $tenant->id])->save();
        $warehouse = Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'branch_id' => Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('id'), 'name' => 'Utama', 'code' => 'UTM']);
        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Telepon', 'product_type' => 'stock']);
        $variant = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => '128 GB', 'sku' => 'TEL-128']);

        $this->actingAs($owner)->get(route('serials.index'))->assertOk()->assertSeeText('Terima serial');
        $this->actingAs($owner)->post(route('serials.receive'), ['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'serial_number' => 'IMEI-123', 'unit_cost' => 3500000])->assertRedirect();

        $this->assertDatabaseHas('serial_numbers', ['tenant_id' => $tenant->id, 'serial_number' => 'IMEI-123', 'status' => 'available']);
        $this->assertDatabaseHas('stock_movements', ['tenant_id' => $tenant->id, 'reference_type' => 'purchase_receipt', 'movement_type' => 'in']);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'inventory.serial.received']);
    }
}
