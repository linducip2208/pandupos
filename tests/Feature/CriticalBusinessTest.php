<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\Module;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subscription;
use App\Models\TenantModule;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\EntitlementService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\StockService;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CriticalBusinessTest extends TestCase
{
    use RefreshDatabase;

    private function setupTenant(string $name = 'Toko Crit'): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision($name, $owner);
        $warehouse = Warehouse::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first()
            ?? Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'G', 'code' => 'G-'.uniqid()]);
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'P', 'sku' => 'SKU-'.uniqid()]);
        $variant = ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'D',
            'sku' => 'V-'.uniqid(), 'purchase_price' => 2500, 'sell_price' => 3500,
        ]);

        return compact('tenant', 'owner', 'warehouse', 'branch', 'product', 'variant');
    }

    public function test_po_does_not_increase_until_received_once(): void
    {
        ['tenant' => $t, 'warehouse' => $w, 'variant' => $v] = $this->setupTenant();
        $supplier = Contact::withoutGlobalScopes()->create(['tenant_id' => $t->id, 'type' => 'supplier', 'name' => 'S']);
        $ps = app(PurchaseService::class);
        $po = $ps->createDraft($t->id, $w->id, $supplier->id, [['product_variant_id' => $v->id, 'quantity' => 100, 'unit_cost' => 2500]]);
        $this->assertEquals(0, app(StockService::class)->onHand($t->id, $w->id, $v->id));
        $ps->receive($po->id);
        $this->assertEquals(100, app(StockService::class)->onHand($t->id, $w->id, $v->id));
        $ps->receive($po->id); // idempotent, not +100 twice
        $this->assertEquals(100, app(StockService::class)->onHand($t->id, $w->id, $v->id));
    }

    public function test_partial_receiving(): void
    {
        ['tenant' => $t, 'warehouse' => $w, 'variant' => $v] = $this->setupTenant('Toko Partial');
        $supplier = Contact::withoutGlobalScopes()->create(['tenant_id' => $t->id, 'type' => 'supplier', 'name' => 'S']);
        $ps = app(PurchaseService::class);
        $po = $ps->createDraft($t->id, $w->id, $supplier->id, [['product_variant_id' => $v->id, 'quantity' => 100, 'unit_cost' => 2500]]);
        $ps->receive($po->id, [['product_variant_id' => $v->id, 'quantity' => 40]]);
        $this->assertEquals(40, app(StockService::class)->onHand($t->id, $w->id, $v->id));
        $this->assertEquals('partial', $po->refresh()->status);
        $ps->receive($po->id, [['product_variant_id' => $v->id, 'quantity' => 60]]);
        $this->assertEquals(100, app(StockService::class)->onHand($t->id, $w->id, $v->id));
        $this->assertEquals('received', $po->refresh()->status);
    }

    public function test_sale_decreases_oversell_fails_void_return_restore(): void
    {
        ['tenant' => $t, 'warehouse' => $w, 'branch' => $b, 'variant' => $v] = $this->setupTenant('Toko Sale');
        $supplier = Contact::withoutGlobalScopes()->create(['tenant_id' => $t->id, 'type' => 'supplier', 'name' => 'S']);
        $customer = Contact::withoutGlobalScopes()->create(['tenant_id' => $t->id, 'type' => 'customer', 'name' => 'C']);
        $ps = app(PurchaseService::class);
        $po = $ps->createDraft($t->id, $w->id, $supplier->id, [['product_variant_id' => $v->id, 'quantity' => 10, 'unit_cost' => 2500]]);
        $ps->receive($po->id);

        $ss = app(SaleService::class);
        $inv = $ss->checkout($t->id, $b->id, $w->id, $customer->id, [['variant_id' => $v->id, 'quantity' => 3, 'unit_price' => 3500]], [['method' => 'cash', 'amount' => 10500]], 'ck-'.uniqid());
        $this->assertEquals(7, app(StockService::class)->onHand($t->id, $w->id, $v->id));

        try {
            $ss->checkout($t->id, $b->id, $w->id, $customer->id, [['variant_id' => $v->id, 'quantity' => 100, 'unit_price' => 3500]], [['method' => 'cash', 'amount' => 350000]], 'ck-'.uniqid());
            $this->fail('Oversell should fail.');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }
        $this->assertEquals(7, app(StockService::class)->onHand($t->id, $w->id, $v->id));

        $ss->return($inv->id, [['variant_id' => $v->id, 'quantity' => 1, 'unit_price' => 3500]]);
        $this->assertEquals(8, app(StockService::class)->onHand($t->id, $w->id, $v->id));

        $manager = User::factory()->create();
        $manager->givePermissionTo('pos.sale.void');
        $ss->void($inv->id, $manager->can('pos.sale.void'));
        // 10 - 3 + 1 = 8, void restores net sold (3-1=2) => 10. No double-restore.
        $this->assertEquals(10, app(StockService::class)->onHand($t->id, $w->id, $v->id));
    }

    public function test_duplicate_idempotency_creates_one_sale(): void
    {
        ['tenant' => $t, 'warehouse' => $w, 'branch' => $b, 'variant' => $v] = $this->setupTenant('Toko Idem');
        $supplier = Contact::withoutGlobalScopes()->create(['tenant_id' => $t->id, 'type' => 'supplier', 'name' => 'S']);
        $customer = Contact::withoutGlobalScopes()->create(['tenant_id' => $t->id, 'type' => 'customer', 'name' => 'C']);
        $ps = app(PurchaseService::class);
        $po = $ps->createDraft($t->id, $w->id, $supplier->id, [['product_variant_id' => $v->id, 'quantity' => 10, 'unit_cost' => 2500]]);
        $ps->receive($po->id);

        $ss = app(SaleService::class);
        $key = 'idem-'.uniqid();
        $a = $ss->checkout($t->id, $b->id, $w->id, $customer->id, [['variant_id' => $v->id, 'quantity' => 1, 'unit_price' => 3500]], [['method' => 'cash', 'amount' => 3500]], $key);
        $b2 = $ss->checkout($t->id, $b->id, $w->id, $customer->id, [['variant_id' => $v->id, 'quantity' => 1, 'unit_price' => 3500]], [['method' => 'cash', 'amount' => 3500]], $key);
        $this->assertEquals($a->id, $b2->id);
        $this->assertEquals(9, app(StockService::class)->onHand($t->id, $w->id, $v->id));
    }

    public function test_disabled_module_and_missing_entitlement_block(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko Block', $owner);
        $owner->refresh();

        $pos = Module::where('slug', 'pos')->firstOrFail();
        TenantModule::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('module_id', $pos->id)->update(['enabled' => false]);
        $this->actingAs($owner)->getJson('/api/v1/pos/ping', ['X-Tenant-ID' => $tenant->id])->assertForbidden();

        // Re-enable module but expire subscription → entitlement gate blocks.
        TenantModule::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('module_id', $pos->id)->update(['enabled' => true]);
        Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->update(['status' => 'expired']);
        app(EntitlementService::class)->forget($tenant->id);
        $this->actingAs($owner)->getJson('/api/v1/pos/ping', ['X-Tenant-ID' => $tenant->id])->assertForbidden();
    }

    public function test_admin_without_void_permission_blocked(): void
    {
        $this->seed(PlatformSeeder::class);
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');
        $this->assertFalse($cashier->can('pos.sale.void'));
    }
}
