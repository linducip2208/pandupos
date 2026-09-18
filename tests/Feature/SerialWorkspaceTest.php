<?php

namespace Tests\Feature;

use App\Livewire\PosKasir;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesInvoice;
use App\Models\SerialNumber;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SerialNumberService;
use App\Services\TenantProvisioningService;
use App\Support\TenantContext;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
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
        $unit = Unit::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Piece', 'short_name' => 'pcs']);
        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Telepon', 'product_type' => 'stock', 'unit_id' => $unit->id]);
        $variant = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => '128 GB', 'sku' => 'TEL-128']);

        $this->actingAs($owner)->get(route('serials.index'))->assertOk()->assertSeeText('Terima serial');
        $this->actingAs($owner)->post(route('serials.receive'), ['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'serial_number' => 'IMEI-123', 'unit_cost' => 3500000])->assertRedirect();

        $this->assertDatabaseHas('serial_numbers', ['tenant_id' => $tenant->id, 'serial_number' => 'IMEI-123', 'status' => 'available']);
        $this->assertDatabaseHas('stock_movements', ['tenant_id' => $tenant->id, 'reference_type' => 'purchase_receipt', 'movement_type' => 'in']);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'inventory.serial.received']);

        TenantContext::set($tenant);
        Livewire::actingAs($owner)->test(PosKasir::class)
            ->call('addToCart', $variant->id, $product->name, $unit->id, 'pcs')
            ->assertSee('IMEI-123')
            ->set('cart.0.serial_number_ids', [(string) SerialNumber::withoutGlobalScopes()->where('serial_number', 'IMEI-123')->value('id')])
            ->assertSet('cart.0.serial_number_ids.0', (string) SerialNumber::withoutGlobalScopes()->where('serial_number', 'IMEI-123')->value('id'));
    }

    public function test_serial_service_rejects_cross_tenant_serial_sale(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Serial Scope A', $owner);
        $otherTenant = app(TenantProvisioningService::class)->provision('Serial Scope B', User::factory()->create());
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Utama', 'code' => 'SER-A']);
        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Scope Phone', 'product_type' => 'stock']);
        $variant = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'Default', 'sku' => 'SER-SCOPE']);
        $invoice = SalesInvoice::withoutGlobalScopes()->create(['uuid' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'status' => 'final', 'payment_status' => 'paid', 'fulfillment_status' => 'fulfilled', 'subtotal' => 1, 'total' => 1]);
        $foreignSerial = SerialNumber::withoutGlobalScopes()->create(['tenant_id' => $otherTenant->id, 'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'serial_number' => 'FOREIGN-SERIAL', 'status' => 'available']);

        $this->expectException(ModelNotFoundException::class);
        app(SerialNumberService::class)->sell($tenant->id, $foreignSerial->id, $invoice->id);
    }
}
