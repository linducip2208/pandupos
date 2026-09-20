<?php

namespace Tests\Feature;

use App\Livewire\PosKasir;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Register;
use App\Models\SalesInvoice;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\RegisterSessionService;
use App\Services\StockService;
use App\Services\TenantProvisioningService;
use App\Support\TenantContext;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * POS checkout component behavior: cash tendering with change, exact split
 * balance enforcement, and drawer netting the invoice total (not tendered).
 */
class PosCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_checkout_accepts_cash_tender_and_reports_change(): void
    {
        [$owner, $tenant, $register, $variant, $unit] = $this->context('POS Tender');
        $owner->givePermissionTo('pos.sale.create');
        $session = app(RegisterSessionService::class)->open($tenant->id, $register->id, $owner->id, 0);
        TenantContext::setId($tenant->id);

        Livewire::actingAs($owner)->test(PosKasir::class)
            ->set('cashSessionId', $session->id)
            ->set('cart', [$this->cartRow($variant->id, $unit->id)])
            ->set('payments', [['method' => 'cash', 'amount' => 1000]])
            ->call('checkout')
            ->assertHasNoErrors()
            ->assertSet('lastChange', 900.0);

        // Drawer nets the invoice total, not the tendered cash.
        $this->assertSame(100.0, app(RegisterSessionService::class)->expectedAmount($session->refresh()));
    }

    public function test_pos_checkout_rejects_overpaid_split_payments(): void
    {
        [$owner, $tenant, $register, $variant, $unit] = $this->context('POS Split');
        $owner->givePermissionTo('pos.sale.create');
        $session = app(RegisterSessionService::class)->open($tenant->id, $register->id, $owner->id, 0);
        TenantContext::setId($tenant->id);

        try {
            Livewire::actingAs($owner)->test(PosKasir::class)
                ->set('cashSessionId', $session->id)
                ->set('cart', [$this->cartRow($variant->id, $unit->id)])
                ->set('payments', [['method' => 'cash', 'amount' => 60], ['method' => 'transfer', 'amount' => 50]])
                ->call('checkout');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        // No invoice may exist for an unbalanced split tender.
        $this->assertSame(0, SalesInvoice::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    private function cartRow(int $variantId, int $unitId): array
    {
        return [
            'variant_id' => $variantId, 'name' => 'Produk Tender', 'base_price' => 100, 'price' => 100, 'qty' => 1,
            'base_unit_id' => $unitId, 'unit_id' => $unitId, 'unit_name' => 'Pcs', 'factor' => 1,
            'price_source' => 'test', 'discount' => 0, 'tax_rate' => 0, 'tax_method' => 'exclusive',
            'inventory_batch_id' => null, 'serial_number_ids' => [],
        ];
    }

    private function context(string $name): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision($name, $owner);
        $owner->refresh();
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Gudang '.$name, 'code' => 'WH-'.uniqid()]);
        $register = Register::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Kasir '.$name]);
        $unit = Unit::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Pieces', 'short_name' => 'Pcs']);
        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Produk '.$name, 'sku' => 'P-'.uniqid(), 'product_type' => 'stock', 'unit_id' => $unit->id, 'track_inventory' => true]);
        $variant = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'Default', 'sku' => 'V-'.uniqid(), 'purchase_price' => 50, 'sell_price' => 100]);
        app(StockService::class)->increase($tenant->id, $warehouse->id, $variant->id, 5, 50, 'opening', null);

        return [$owner, $tenant, $register, $variant, $unit];
    }
}
