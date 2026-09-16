<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\CustomerLogin;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
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
        if (! $owner->hasRole('tenant-owner')) {
            $owner->assignRole('tenant-owner');
        }
        $tenant = $owner->currentTenant()->withoutGlobalScopes()->first()
            ?? app(TenantProvisioningService::class)->provision('Toko Demo', $owner);
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first()
            ?? Branch::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Pusat', 'code' => 'PST', 'is_active' => true]);
        foreach (['manager', 'cashier', 'warehouse', 'purchasing', 'sales'] as $role) {
            $user = User::updateOrCreate(['email' => $role.'@demo.local'], ['name' => str($role)->headline().' Demo', 'password' => 'password', 'current_tenant_id' => $tenant->id]);
            $user->memberships()->withoutGlobalScopes()->updateOrCreate(['tenant_id' => $tenant->id], ['branch_ids' => [$branch->id]]);
            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }
        }
        $warehouse = Warehouse::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'GDG-PST'],
            ['branch_id' => $branch->id, 'name' => 'Gudang Pusat', 'is_active' => true]
        );
        $supplier = Contact::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'type' => 'supplier', 'name' => 'Supplier Demo']
        );
        $customer = Contact::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'type' => 'customer', 'name' => 'Pelanggan Demo']
        );
        $customer->update(['email' => 'customer@demo.local']);
        CustomerLogin::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'contact_id' => $customer->id],
            ['email' => 'customer@demo.local', 'password' => 'password', 'is_active' => true]
        );

        foreach ([['Indomie Goreng', 'IND-GRG', 2500, 3500], ['Teh Botol', 'TEH-BTL', 3000, 4500]] as [$name, $sku, $buy, $sell]) {
            $p = Product::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'sku' => $sku],
                ['name' => $name, 'is_active' => true]
            );
            $v = ProductVariant::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'sku' => $sku.'-V'],
                ['product_id' => $p->id, 'name' => 'Default', 'purchase_price' => $buy, 'sell_price' => $sell]
            );
            if (! StockMovement::withoutGlobalScopes()->where('product_variant_id', $v->id)->exists()) {
                app(PurchaseService::class)->createDraft($tenant->id, $warehouse->id, $supplier->id, [
                    ['product_variant_id' => $v->id, 'quantity' => 50, 'unit_cost' => $buy],
                ]);
            }
        }
    }
}
