<?php

namespace Tests\Feature;

use App\Livewire\PosKasir;
use App\Models\Contact;
use App\Models\CustomerGroup;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Services\TenantProvisioningService;
use App\Support\TenantContext;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PriceListWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_list_ui_audits_rule_and_pos_exposes_selected_source(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Price Tenant', $owner);
        $owner->forceFill(['current_tenant_id' => $tenant->id])->save();
        $unit = Unit::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Piece', 'short_name' => 'pcs']);
        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Produk VIP', 'unit_id' => $unit->id]);
        $variant = ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'Default', 'sku' => 'VIP-1', 'sell_price' => 12000,
        ]);
        $group = CustomerGroup::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'VIP']);
        $customer = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'type' => 'customer', 'name' => 'Budi']);
        $customer->forceFill(['customer_group_id' => $group->id])->save();

        $this->actingAs($owner)->get(route('price-lists.index'))->assertOk()->assertSeeText('Aturan harga baru');
        $this->actingAs($owner)->post(route('price-lists.store'), [
            'name' => 'VIP September', 'scope' => 'customer_group', 'customer_group_id' => $group->id,
            'starts_at' => now()->subDay()->format('Y-m-d\TH:i'), 'ends_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'priority' => 50, 'is_active' => 1,
            'item' => ['product_variant_id' => $variant->id, 'price' => 9000, 'minimum_quantity' => 0],
        ])->assertRedirect();
        $this->assertDatabaseHas('price_lists', ['tenant_id' => $tenant->id, 'name' => 'VIP September', 'priority' => 50]);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'price_list.changed']);
        $list = PriceList::firstOrFail();
        $this->actingAs($owner)->put(route('price-lists.update', $list), [
            'name' => 'VIP Prioritas', 'scope' => 'customer_group', 'customer_group_id' => $group->id,
            'priority' => 60, 'is_active' => 1,
            'item' => ['product_variant_id' => $variant->id, 'price' => 9000, 'minimum_quantity' => 0],
        ])->assertRedirect();
        $this->assertDatabaseHas('price_lists', ['id' => $list->id, 'name' => 'VIP Prioritas', 'priority' => 60]);

        TenantContext::set($tenant);
        Livewire::actingAs($owner)->test(PosKasir::class)
            ->set('customerId', $customer->id)
            ->call('addToCart', $variant->id, $product->name, $unit->id, 'pcs')
            ->assertSet('cart.0.price', 9000.0)
            ->assertSet('cart.0.price_source', 'VIP Prioritas');
    }
}
