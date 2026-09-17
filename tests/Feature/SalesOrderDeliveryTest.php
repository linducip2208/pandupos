<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\Product;
use App\Models\ProductVariant;
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
}
