<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\StockReservationService;
use App\Services\StockService;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StockLocationReservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_location_stock_and_reservation_prevent_oversell_until_release(): void
    {
        [$tenant, $warehouse, $variant, $location] = $this->context();
        $stock = app(StockService::class);
        $stock->increase($tenant->id, $warehouse->id, $variant->id, 10, 12, 'opening', 1, null, null, $location->id);

        $service = app(StockReservationService::class);
        $reservation = $service->reserve([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'warehouse_location_id' => $location->id,
            'product_variant_id' => $variant->id,
            'quantity' => 6,
            'source_type' => 'sales_order',
            'source_id' => 100,
            'idempotency_key' => 'order-100-line-1',
            'expires_at' => now()->addHour(),
        ]);

        $this->assertEquals(10, $stock->onHandAtLocation($tenant->id, $warehouse->id, $variant->id, $location->id));
        $this->assertEquals(4, $stock->availableToPromise($tenant->id, $warehouse->id, $variant->id));
        $this->assertSame($reservation->id, $service->reserve([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'quantity' => 6,
            'source_type' => 'sales_order',
            'idempotency_key' => 'order-100-line-1',
        ])->id);

        try {
            $stock->decrease($tenant->id, $warehouse->id, $variant->id, 5, 'sale', 200);
            $this->fail('Unreserved sale must not consume reserved stock.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $service->release($reservation);
        $this->assertEquals(10, $stock->availableToPromise($tenant->id, $warehouse->id, $variant->id));
    }

    public function test_consuming_reservation_posts_one_location_ledger_movement_and_audit(): void
    {
        [$tenant, $warehouse, $variant, $location] = $this->context();
        $stock = app(StockService::class);
        $stock->increase($tenant->id, $warehouse->id, $variant->id, 10, 12, 'opening', 1, null, null, $location->id);
        $service = app(StockReservationService::class);
        $reservation = $service->reserve([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $warehouse->id,
            'warehouse_location_id' => $location->id,
            'product_variant_id' => $variant->id,
            'quantity' => 3,
            'source_type' => 'held_sale',
            'source_id' => 300,
        ]);

        $consumed = $service->consume($reservation, 'sale', 301);

        $this->assertSame('consumed', $consumed->status);
        $this->assertEquals(7, $stock->onHand($tenant->id, $warehouse->id, $variant->id));
        $this->assertEquals(7, $stock->onHandAtLocation($tenant->id, $warehouse->id, $variant->id, $location->id));
        $this->assertEquals(7, $stock->availableToPromise($tenant->id, $warehouse->id, $variant->id));
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'action' => 'inventory.reservation.consumed',
            'subject_id' => $reservation->id,
        ]);
        $this->assertSame(2, AuditLog::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_expiry_cleanup_is_tenant_scoped_and_audited(): void
    {
        [$tenant, $warehouse, $variant] = $this->context();
        app(StockService::class)->increase($tenant->id, $warehouse->id, $variant->id, 1, 12, 'opening', 1);
        $reservation = app(StockReservationService::class)->reserve([
            'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
            'quantity' => 1, 'source_type' => 'sales_order', 'source_id' => 101, 'expires_at' => now()->subMinute(),
        ]);

        $this->artisan('inventory:expire-reservations', ['--tenant' => $tenant->id])->assertSuccessful();

        $this->assertSame('expired', $reservation->refresh()->status);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'inventory.reservation.expired', 'subject_id' => $reservation->id]);
    }

    private function context(): array
    {
        $this->seed(PlatformSeeder::class);
        $tenant = app(TenantProvisioningService::class)->provision('Reservation Tenant', User::factory()->create());
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Main', 'code' => 'MAIN',
        ]);
        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => 'Reserved Item', 'sku' => 'RSV',
            'product_type' => 'stock', 'track_inventory' => true,
        ]);
        $variant = ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id,
            'name' => 'Default', 'sku' => 'RSV-1', 'purchase_price' => 12, 'sell_price' => 20,
        ]);
        $location = WarehouseLocation::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id,
            'code' => 'A-R1-S1-B1', 'zone' => 'A', 'rack' => 'R1', 'shelf' => 'S1', 'bin' => 'B1',
        ]);

        return [$tenant, $warehouse, $variant, $location];
    }
}
