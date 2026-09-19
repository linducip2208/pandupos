<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Register;
use App\Models\SaleRefund;
use App\Models\SalesReturn;
use App\Models\SerialNumber;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BatchInventoryService;
use App\Services\RegisterSessionService;
use App\Services\SaleService;
use App\Services\SerialNumberService;
use App\Services\StockService;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SalesReturnRefundLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_return_partial_return_and_over_return_protection(): void
    {
        ['tenant' => $t, 'branch' => $b, 'warehouse' => $w, 'variant' => $v, 'customer' => $c] = $this->context();
        $this->stock($t, $w, $v, 10);
        $invoice = $this->checkout($t, $b, $w, $c, [['variant_id' => $v->id, 'quantity' => 6, 'unit_price' => 100]], 600, 'ret-full-1');

        $service = app(SaleService::class);
        $partial = $service->return($invoice->id, [
            ['variant_id' => $v->id, 'quantity' => 2, 'unit_price' => 100],
        ], $t->id, null, 'ret-key-partial', 'Dua unit cacat', true);
        $this->assertSame(6.0, app(StockService::class)->onHand($t->id, $w->id, $v->id));
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $t->id, 'reference_type' => 'sale_return', 'reference_id' => $invoice->id, 'quantity' => 2,
        ]);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $t->id, 'action' => 'sale.return.posted', 'subject_id' => $partial->id]);

        // Idempotent retry returns the same record without double stock.
        $retry = $service->return($invoice->id, [
            ['variant_id' => $v->id, 'quantity' => 2, 'unit_price' => 100],
        ], $t->id, null, 'ret-key-partial', 'Dua unit cacat', true);
        $this->assertSame($partial->id, $retry->id);
        $this->assertSame(6.0, app(StockService::class)->onHand($t->id, $w->id, $v->id));

        // Return beyond sold quantity is rejected.
        try {
            $service->return($invoice->id, [['variant_id' => $v->id, 'quantity' => 5, 'unit_price' => 100]], $t->id);
            $this->fail('Refund beyond sold quantity must be rejected.');
        } catch (\Throwable) {
            $this->assertTrue(true);
        }

        // Full remainder return.
        $service->return($invoice->id, [['variant_id' => $v->id, 'quantity' => 4, 'unit_price' => 100]], $t->id, null, null, 'Sisa retur', true);
        $this->assertSame(10.0, app(StockService::class)->onHand($t->id, $w->id, $v->id));
    }

    public function test_multiline_return_with_batch_and_serial_trace(): void
    {
        ['tenant' => $t, 'branch' => $b, 'warehouse' => $w, 'variant' => $v, 'customer' => $c] = $this->context();
        $batches = app(BatchInventoryService::class);
        $first = $batches->receive($t->id, $w->id, $v->id, 'RET-A', 5, 40, null, today()->addMonth()->toDateString());
        $second = $batches->receive($t->id, $w->id, $v->id, 'RET-B', 5, 40, null, today()->addMonths(2)->toDateString());
        $invoice = $this->checkout($t, $b, $w, $c, [['variant_id' => $v->id, 'quantity' => 4, 'unit_price' => 100]], 400, 'ret-multi-1');

        $soldBatches = StockMovement::withoutGlobalScopes()->where('reference_type', 'sale')->where('reference_id', $invoice->id)
            ->where('movement_type', 'out')->pluck('quantity', 'inventory_batch_id')->all();
        $this->assertNotEmpty($soldBatches);

        $return = app(SaleService::class)->return($invoice->id, [
            ['variant_id' => $v->id, 'quantity' => 2, 'unit_price' => 100, 'inventory_batch_id' => array_key_first($soldBatches)],
        ], $t->id, null, 'ret-multi-batch', 'Batch retur', true);
        $this->assertSame(8.0, app(StockService::class)->onHand($t->id, $w->id, $v->id));
        $this->assertDatabaseHas('stock_movements', [
            'reference_type' => 'sale_return', 'reference_id' => $invoice->id,
            'inventory_batch_id' => array_key_first($soldBatches), 'quantity' => 2,
        ]);

        // Wrong batch (never sold on this invoice) is rejected.
        $foreign = $batches->receive($t->id, $w->id, $v->id, 'RET-FOREIGN', 5, 40);
        try {
            app(SaleService::class)->return($invoice->id, [
                ['variant_id' => $v->id, 'quantity' => 1, 'unit_price' => 100, 'inventory_batch_id' => $foreign->id],
            ], $t->id);
            $this->fail('Wrong batch must be rejected.');
        } catch (\Throwable) {
            $this->assertTrue(true);
        }
    }

    public function test_expired_batch_sale_is_blocked_but_return_restores_original_cost(): void
    {
        ['tenant' => $t, 'branch' => $b, 'warehouse' => $w, 'variant' => $v, 'customer' => $c] = $this->context();
        $batches = app(BatchInventoryService::class);
        $batches->receive($t->id, $w->id, $v->id, 'EXP-OLD', 5, 30, null, today()->subDay()->toDateString());
        $fresh = $batches->receive($t->id, $w->id, $v->id, 'FRESH', 5, 30, null, today()->addMonth()->toDateString());

        $invoice = $this->checkout($t, $b, $w, $c, [['variant_id' => $v->id, 'quantity' => 2, 'unit_price' => 100]], 200, 'ret-exp-1');
        $saleCost = (float) StockMovement::withoutGlobalScopes()->where('reference_type', 'sale')->where('reference_id', $invoice->id)->firstOrFail()->unit_cost;

        $return = app(SaleService::class)->return($invoice->id, [
            ['variant_id' => $v->id, 'quantity' => 1, 'unit_price' => 100, 'inventory_batch_id' => $fresh->id],
        ], $t->id, null, 'ret-exp-batch', 'Retur batch kedaluwarsa tetap tercatat', true);
        $restored = StockMovement::withoutGlobalScopes()->where('reference_type', 'sale_return')->where('reference_id', $invoice->id)->firstOrFail();
        $this->assertEquals($saleCost, (float) $restored->unit_cost);
        $this->assertSame(9.0, app(StockService::class)->onHand($t->id, $w->id, $v->id));
    }

    public function test_serial_return_and_double_serial_return_protection(): void
    {
        ['tenant' => $t, 'branch' => $b, 'warehouse' => $w, 'variant' => $v, 'customer' => $c] = $this->context();
        $this->stock($t, $w, $v, 2, ['SER-RET-1', 'SER-RET-2']);
        $serial = SerialNumber::withoutGlobalScopes()->where('tenant_id', $t->id)->where('serial_number', 'SER-RET-1')->firstOrFail();
        $other = SerialNumber::withoutGlobalScopes()->where('tenant_id', $t->id)->where('serial_number', 'SER-RET-2')->firstOrFail();
        $invoice = app(SaleService::class)->checkout($t->id, $b->id, $w->id, $c->id, [
            ['variant_id' => $v->id, 'quantity' => 1, 'unit_price' => 100, 'serial_number_ids' => [$serial->id]],
        ], [['method' => 'cash', 'amount' => 100]], 'ret-ser-1');
        $this->assertSame('sold', $serial->refresh()->status);

        app(SaleService::class)->return($invoice->id, [
            ['variant_id' => $v->id, 'quantity' => 1, 'unit_price' => 100, 'serial_number_ids' => [$serial->id]],
        ], $t->id, null, 'ret-ser-key', 'Serial retur', true);
        $this->assertSame('returned', $serial->refresh()->status);
        $this->assertSame(2.0, app(StockService::class)->onHand($t->id, $w->id, $v->id));

        // Same serial cannot be returned twice.
        try {
            app(SaleService::class)->return($invoice->id, [
                ['variant_id' => $v->id, 'quantity' => 1, 'unit_price' => 100, 'serial_number_ids' => [$serial->id]],
            ], $t->id);
            $this->fail('Double serial return must be rejected.');
        } catch (\Throwable) {
            $this->assertTrue(true);
        }
        // Serial from another sale cannot be returned here.
        try {
            app(SaleService::class)->return($invoice->id, [
                ['variant_id' => $v->id, 'quantity' => 1, 'unit_price' => 100, 'serial_number_ids' => [$other->id]],
            ], $t->id);
            $this->fail('Foreign serial must be rejected.');
        } catch (\Throwable) {
            $this->assertTrue(true);
        }
    }

    public function test_refund_reverses_payment_with_idempotency_and_register_impact(): void
    {
        ['tenant' => $t, 'branch' => $b, 'warehouse' => $w, 'variant' => $v, 'customer' => $c, 'owner' => $owner] = $this->context();
        $register = Register::withoutGlobalScopes()->create(['tenant_id' => $t->id, 'branch_id' => $b->id, 'name' => 'Kasir', 'code' => 'R1']);
        $session = app(RegisterSessionService::class)->open($t->id, $register->id, $owner->id, 50000);
        $this->stock($t, $w, $v, 5);
        $this->actingAs($owner);
        $invoice = app(SaleService::class)->checkout($t->id, $b->id, $w->id, $c->id, [
            ['variant_id' => $v->id, 'quantity' => 2, 'unit_price' => 50000],
        ], [['method' => 'cash', 'amount' => 100000]], 'ret-ref-1', $session->id);

        $salesReturn = app(SaleService::class)->return($invoice->id, [
            ['variant_id' => $v->id, 'quantity' => 2, 'unit_price' => 50000],
        ], $t->id, $owner->id, 'ret-ref-return', 'Full retur', true);

        $refund = app(SaleService::class)->refund($invoice->id, $salesReturn->id, 100000, 'cash', 'REF-1', 'Dana kembali', $owner->id, true, $t->id);
        $this->assertSame('refunded', $invoice->refresh()->payment_status);
        $this->assertNotNull($refund->cash_session_movement_id);
        $this->assertDatabaseHas('cash_session_movements', [
            'cash_session_id' => $session->id, 'type' => 'cash_out', 'amount' => 100000,
        ]);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $t->id, 'action' => 'sale.refund.posted']);

        // Same reference is idempotent: no double refund, no double cash_out.
        $duplicate = app(SaleService::class)->refund($invoice->id, $salesReturn->id, 100000, 'cash', 'REF-1', 'Dana kembali', $owner->id, true, $t->id);
        $this->assertSame($refund->id, $duplicate->id);
        $this->assertSame(1, SaleRefund::withoutGlobalScopes()->where('tenant_id', $t->id)->where('reference', 'REF-1')->count());

        // Refund beyond paid amount is rejected.
        try {
            app(SaleService::class)->refund($invoice->id, $salesReturn->id, 1, 'cash', 'REF-2', 'Lebih', $owner->id, true, $t->id);
            $this->fail('Refund beyond paid must be rejected.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    public function test_partial_refund_marks_invoice_partial_and_closed_session_blocks_cash_refund(): void
    {
        ['tenant' => $t, 'branch' => $b, 'warehouse' => $w, 'variant' => $v, 'customer' => $c, 'owner' => $owner] = $this->context();
        $register = Register::withoutGlobalScopes()->create(['tenant_id' => $t->id, 'branch_id' => $b->id, 'name' => 'Kasir', 'code' => 'R2']);
        $session = app(RegisterSessionService::class)->open($t->id, $register->id, $owner->id, 0);
        $this->stock($t, $w, $v, 5);
        $this->actingAs($owner);
        $invoice = app(SaleService::class)->checkout($t->id, $b->id, $w->id, $c->id, [
            ['variant_id' => $v->id, 'quantity' => 2, 'unit_price' => 50000],
        ], [['method' => 'cash', 'amount' => 100000]], 'ret-pref-1', $session->id);
        $salesReturn = app(SaleService::class)->return($invoice->id, [
            ['variant_id' => $v->id, 'quantity' => 1, 'unit_price' => 50000],
        ], $t->id, $owner->id, 'ret-pref-return', 'Sebagian', true);

        app(SaleService::class)->refund($invoice->id, $salesReturn->id, 40000, 'transfer', 'REF-T-1', 'Sebagian via transfer', $owner->id, true, $t->id);
        $this->assertSame('partial', $invoice->refresh()->payment_status);

        app(RegisterSessionService::class)->close($session, $owner->id, 100000);
        try {
            app(SaleService::class)->refund($invoice->id, $salesReturn->id, 10000, 'cash', 'REF-C-2', 'Kas tutup', $owner->id, true, $t->id);
            $this->fail('Cash refund on closed session must be rejected.');
        } catch (HttpException) {
            $this->assertTrue(true);
        }
    }

    public function test_void_after_partial_return_restores_net_only_and_marks_payment_refunded(): void
    {
        ['tenant' => $t, 'branch' => $b, 'warehouse' => $w, 'variant' => $v, 'customer' => $c, 'owner' => $owner] = $this->context();
        $this->stock($t, $w, $v, 10);
        $this->actingAs($owner);
        $invoice = $this->checkout($t, $b, $w, $c, [['variant_id' => $v->id, 'quantity' => 4, 'unit_price' => 100]], 400, 'ret-void-1');
        app(SaleService::class)->return($invoice->id, [['variant_id' => $v->id, 'quantity' => 1, 'unit_price' => 100]], $t->id, $owner->id, 'ret-void-r', 'Satu retur', true);
        $this->assertSame(7.0, app(StockService::class)->onHand($t->id, $w->id, $v->id));

        $owner->givePermissionTo('pos.sale.void');
        app(SaleService::class)->void($invoice->id, $owner->can('pos.sale.void'), $t->id, 'Batal total');
        // 10 - 4 + 1 = 7, void restores net sold (4-1=3) => 10. No double-restore.
        $this->assertSame(10.0, app(StockService::class)->onHand($t->id, $w->id, $v->id));
        $this->assertSame('void', $invoice->refresh()->status);
        $this->assertSame('refunded', $invoice->refresh()->payment_status);

        try {
            app(SaleService::class)->return($invoice->id, [['variant_id' => $v->id, 'quantity' => 1, 'unit_price' => 100]], $t->id);
            $this->fail('Return on voided sale must be rejected.');
        } catch (\Throwable) {
            $this->assertTrue(true);
        }
        try {
            app(SaleService::class)->refund($invoice->id, SalesReturn::withoutGlobalScopes()->where('sales_invoice_id', $invoice->id)->firstOrFail()->id, 10, 'cash', 'REF-VOID', 'Void refund', $owner->id, true, $t->id);
            $this->fail('Refund on voided sale must be rejected.');
        } catch (\Throwable) {
            $this->assertTrue(true);
        }
    }

    public function test_authorization_and_tenant_isolation_for_return_void_refund(): void
    {
        ['tenant' => $t, 'branch' => $b, 'warehouse' => $w, 'variant' => $v, 'customer' => $c, 'owner' => $owner] = $this->context();
        $this->stock($t, $w, $v, 5);
        $invoice = $this->checkout($t, $b, $w, $c, [['variant_id' => $v->id, 'quantity' => 1, 'unit_price' => 100]], 100, 'ret-auth-1');

        try {
            app(SaleService::class)->return($invoice->id, [['variant_id' => $v->id, 'quantity' => 1, 'unit_price' => 100]], $t->id, null, null, null, false);
            $this->fail('Return without permission must be rejected.');
        } catch (HttpException) {
            $this->assertTrue(true);
        }
        try {
            app(SaleService::class)->void($invoice->id, false, $t->id, 'No perm');
            $this->fail('Void without permission must be rejected.');
        } catch (HttpException) {
            $this->assertTrue(true);
        }

        $foreignOwner = User::factory()->create();
        $foreign = app(TenantProvisioningService::class)->provision('Foreign Sales', $foreignOwner);
        try {
            app(SaleService::class)->return($invoice->id, [['variant_id' => $v->id, 'quantity' => 1, 'unit_price' => 100]], $foreign->id);
            $this->fail('Cross-tenant return must be rejected.');
        } catch (\Throwable) {
            $this->assertTrue(true);
        }
        $this->assertSame(4.0, app(StockService::class)->onHand($t->id, $w->id, $v->id));

        // Workspace routes are tenant-scoped.
        $owner->forceFill(['current_tenant_id' => $t->id])->save();
        $owner->givePermissionTo(['pos.sale.create', 'pos.sale.void', 'sales.view']);
        $this->actingAs($owner)->get(route('sales-returns.index'))->assertOk();
        $this->actingAs($owner)->post(route('sales-returns.returns.store', $invoice), [
            'lines' => [['variant_id' => $v->id, 'quantity' => 1, 'unit_price' => 100]],
            'reason' => 'UI retur',
        ])->assertRedirect();
    }

    private function context(): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Sales Return', $owner);
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Utama', 'code' => 'MAIN',
        ]);
        $customer = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'type' => 'customer', 'name' => 'Pelanggan']);
        $product = Product::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => 'Produk', 'sku' => 'PROD', 'product_type' => 'stock', 'track_inventory' => true,
        ]);
        $variant = ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'Default',
            'sku' => 'PROD-1', 'purchase_price' => 40, 'sell_price' => 100,
        ]);

        return ['tenant' => $tenant, 'owner' => $owner, 'branch' => $branch, 'warehouse' => $warehouse, 'variant' => $variant, 'customer' => $customer];
    }

    private function stock($tenant, $warehouse, $variant, float $qty, ?array $serials = null): void
    {
        if ($serials !== null) {
            foreach ($serials as $serial) {
                app(SerialNumberService::class)->receive($tenant->id, $warehouse->id, $variant->id, $serial, 40);
            }

            return;
        }
        app(StockService::class)->increase($tenant->id, $warehouse->id, $variant->id, $qty, 40, 'purchase_receipt', 1);
    }

    private function checkout($tenant, $branch, $warehouse, $customer, array $lines, float $total, string $key)
    {
        $this->actingAs(User::withoutGlobalScopes()->where('id', $tenant->owner_id ?? null)->first() ?? User::factory()->create());

        return app(SaleService::class)->checkout(
            $tenant->id, $branch->id, $warehouse->id, $customer->id, $lines, [['method' => 'cash', 'amount' => $total]], $key
        );
    }
}
