<?php

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseService;
use App\Services\TenantProvisioningService;
use Illuminate\Database\Seeder;

class DemoTenantSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction() && ! env('SEED_DEMO_IN_PROD', false)) {
            return;
        }

        $owner = User::updateOrCreate(['email' => 'owner@demo.local'], [
            'name' => 'Demo Owner', 'password' => 'password',
        ]);
        $tenant = app(TenantProvisioningService::class)->provision('Toko Demo', $owner);
        $warehouse = Warehouse::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $supplier = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'type' => 'supplier', 'name' => 'Supplier Demo']);
        $customer = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'type' => 'customer', 'name' => 'Pelanggan Demo']);

        foreach ([['Indomie Goreng', 'IND-GRG', 2500, 3500], ['Teh Botol', 'TEH-BTL', 3000, 4500]] as [$name, $sku, $buy, $sell]) {
            $p = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => $name, 'sku' => $sku.'-'.uniqid()]);
            $v = ProductVariant::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id, 'product_id' => $p->id, 'name' => 'Default',
                'sku' => $sku.'-V', 'purchase_price' => $buy, 'sell_price' => $sell,
            ]);
            app(PurchaseService::class)->createDraft($tenant->id, $warehouse->id, $supplier->id, [
                ['product_variant_id' => $v->id, 'quantity' => 50, 'unit_cost' => $buy],
            ]);
        }
    }
}
