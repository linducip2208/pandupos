<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesOrderService;
use App\Services\StockService;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesOrderDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmation_reserves_and_partial_delivery_posts_stock_only_when_delivered(): void
    {
        $this->seed(PlatformSeeder::class);
        $user = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Order Tenant', $user);
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Main', 'code' => 'SO']);
        $customer = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'type' => 'customer', 'name' => 'Customer']);
        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Item', 'sku' => 'SO']);
        $variant = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'Default', 'sku' => 'SO-1']);
        $stock = app(StockService::class);
        $stock->increase($tenant->id, $warehouse->id, $variant->id, 10, 5000, 'opening', null);
        $service = app(SalesOrderService::class);
        $order = $service->create($tenant->id, [
            'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'contact_id' => $customer->id,
            'lines' => [['product_variant_id' => $variant->id, 'quantity' => 6, 'unit_price' => 8000]],
        ], $user->id);

        $confirmed = $service->confirm($order, $user->id);
        $this->assertSame('confirmed', $confirmed->status);
        $this->assertSame(10.0, $stock->onHand($tenant->id, $warehouse->id, $variant->id));
        $this->assertSame(4.0, $stock->availableToPromise($tenant->id, $warehouse->id, $variant->id));
        $line = $confirmed->lines->first();

        $first = $service->deliver($confirmed, [['sales_order_line_id' => $line->id, 'quantity' => 2]], $user->id);
        $this->assertSame('partial', $order->fresh()->status);
        $this->assertSame(8.0, $stock->onHand($tenant->id, $warehouse->id, $variant->id));
        $this->assertSame(4.0, (float) $line->reservation()->firstOrFail()->quantity);
        $this->assertCount(1, $first->lines);

        $service->deliver($order->fresh(), [['sales_order_line_id' => $line->id, 'quantity' => 4]], $user->id);
        $this->assertSame('fulfilled', $order->fresh()->status);
        $this->assertSame(4.0, $stock->onHand($tenant->id, $warehouse->id, $variant->id));
        $this->assertSame('consumed', $line->reservation()->firstOrFail()->status);
    }

    public function test_cancelling_confirmed_order_releases_active_reservation_without_changing_physical_stock(): void
    {
        $this->seed(PlatformSeeder::class);
        $user = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Cancellation Tenant', $user);
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Main', 'code' => 'CANCEL']);
        $customer = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'type' => 'customer', 'name' => 'Customer']);
        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Item', 'sku' => 'CANCEL']);
        $variant = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'Default', 'sku' => 'CANCEL-1']);
        $stock = app(StockService::class);
        $stock->increase($tenant->id, $warehouse->id, $variant->id, 10, 5000, 'opening', null);
        $service = app(SalesOrderService::class);
        $order = $service->create($tenant->id, [
            'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'contact_id' => $customer->id,
            'lines' => [['product_variant_id' => $variant->id, 'quantity' => 6, 'unit_price' => 8000]],
        ], $user->id);

        $confirmed = $service->confirm($order, $user->id);
        $this->assertEquals(4, $stock->availableToPromise($tenant->id, $warehouse->id, $variant->id));
        $cancelled = $service->cancel($confirmed, $user->id);

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertEquals(10, $stock->onHand($tenant->id, $warehouse->id, $variant->id));
        $this->assertEquals(10, $stock->availableToPromise($tenant->id, $warehouse->id, $variant->id));
        $this->assertSame('released', $confirmed->lines->first()->reservation()->firstOrFail()->refresh()->status);
    }

    public function test_fully_delivered_order_creates_one_unpaid_invoice_without_second_stock_mutation(): void
    {
        $this->seed(PlatformSeeder::class);
        $user = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Invoice Order Tenant', $user);
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Invoice Warehouse', 'code' => 'INV-SO']);
        $customer = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'type' => 'customer', 'name' => 'Invoice Customer']);
        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Invoice Item', 'sku' => 'INV-SO-P']);
        $variant = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'Default', 'sku' => 'INV-SO-V']);
        $stock = app(StockService::class);
        $stock->increase($tenant->id, $warehouse->id, $variant->id, 5, 100, 'opening', null);
        $service = app(SalesOrderService::class);
        $order = $service->create($tenant->id, [
            'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'contact_id' => $customer->id,
            'lines' => [['product_variant_id' => $variant->id, 'quantity' => 2, 'unit_price' => 250]],
        ], $user->id);
        $order = $service->confirm($order, $user->id);
        $service->deliver($order, [['sales_order_line_id' => $order->lines->first()->id, 'quantity' => 2]], $user->id);
        $afterDelivery = $stock->onHand($tenant->id, $warehouse->id, $variant->id);

        $first = $service->invoice($order->fresh(), $user->id);
        $retry = $service->invoice($order->fresh(), $user->id);

        $this->assertSame($first->id, $retry->id);
        $this->assertSame('final', $first->status);
        $this->assertSame('fulfilled', $first->fulfillment_status);
        $this->assertSame('unpaid', $first->payment_status);
        $this->assertSame(500.0, (float) $first->total);
        $this->assertSame($afterDelivery, $stock->onHand($tenant->id, $warehouse->id, $variant->id));
        $this->assertDatabaseCount('sales_invoices', 1);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'sales_invoice.created_from_order']);

        $service->payInvoice($first, 200, 'transfer', 'SO-PAY-1', $user->id);
        $this->assertSame('partial', $first->fresh()->payment_status);
        $service->payInvoice($first->fresh(), 300, 'cash', 'SO-PAY-2', $user->id);
        $this->assertSame('paid', $first->fresh()->payment_status);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'sales_invoice.payment.recorded']);
    }

    public function test_workspace_confirm_deliver_and_cancel_routes_are_tenant_scoped(): void
    {
        $this->seed(PlatformSeeder::class);
        $user = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Workspace Order Tenant', $user);
        $user->givePermissionTo('sales.create');
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Main', 'code' => 'WORKSPACE']);
        $customer = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'type' => 'customer', 'name' => 'Customer']);
        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Item', 'sku' => 'WORKSPACE']);
        $variant = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'Default', 'sku' => 'WORKSPACE-1']);
        app(StockService::class)->increase($tenant->id, $warehouse->id, $variant->id, 3, 100, 'opening', 1);
        $order = app(SalesOrderService::class)->create($tenant->id, [
            'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'contact_id' => $customer->id,
            'lines' => [['product_variant_id' => $variant->id, 'quantity' => 3, 'unit_price' => 200]],
        ], $user->id);

        $this->actingAs($user)->post(route('sales-orders.confirm', $order))->assertRedirect();
        $this->assertSame('confirmed', $order->fresh()->status);
        $line = $order->fresh('lines')->lines->first();
        $this->post(route('sales-orders.deliver', $order), [
            'lines' => [['sales_order_line_id' => $line->id, 'quantity' => 3]],
        ])->assertRedirect();
        $this->assertSame('fulfilled', $order->fresh()->status);

        $other = User::factory()->create();
        $otherTenant = app(TenantProvisioningService::class)->provision('Other Workspace Tenant', $other);
        $other->givePermissionTo('sales.create');
        $this->actingAs($other)->post(route('sales-orders.cancel', $order))->assertNotFound();
        $this->assertSame($tenant->id, $order->fresh()->tenant_id);
        $this->assertNotSame($otherTenant->id, $order->fresh()->tenant_id);
    }

    public function test_workspace_creates_multi_line_order_and_rejects_a_supplier_as_customer(): void
    {
        $this->seed(PlatformSeeder::class);
        $user = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Multi Line Sales', $user);
        $user->forceFill(['current_tenant_id' => $tenant->id])->save();
        $user->givePermissionTo('sales.create');
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Main', 'code' => 'MULTI']);
        $customer = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'type' => 'customer', 'name' => 'Customer']);
        $unit = Unit::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Piece', 'short_name' => 'pcs']);
        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Item', 'sku' => 'MULTI', 'unit_id' => $unit->id]);
        $first = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'One', 'sku' => 'MULTI-1']);
        $second = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'Two', 'sku' => 'MULTI-2']);

        $this->actingAs($user)->post(route('sales-orders.store'), [
            'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'contact_id' => $customer->id, 'order_date' => today()->toDateString(),
            'lines' => [
                ['product_variant_id' => $first->id, 'unit_id' => $unit->id, 'quantity' => 2, 'unit_price' => 100],
                ['product_variant_id' => $second->id, 'unit_id' => $unit->id, 'quantity' => 3, 'unit_price' => 200],
            ],
        ])->assertRedirect()->assertSessionHas('status');
        $this->assertSame(2, SalesOrder::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail()->lines()->count());

        $supplier = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'type' => 'supplier', 'name' => 'Supplier']);
        $this->actingAs($user)->post(route('sales-orders.store'), [
            'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id, 'contact_id' => $supplier->id, 'order_date' => today()->toDateString(),
            'lines' => [['product_variant_id' => $first->id, 'unit_id' => $unit->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertStatus(422);
    }
}
