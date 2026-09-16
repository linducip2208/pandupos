<?php

namespace Tests\Feature;

use App\Http\Controllers\Platform\AffiliateController;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RemainingCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_fulfillment_status_set_on_checkout(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko Full', $owner);
        $w = Warehouse::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first()
            ?? Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'G', 'code' => 'G-'.uniqid()]);
        $b = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $p = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'X', 'sku' => 'X-'.uniqid()]);
        $v = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $p->id, 'name' => 'D', 'sku' => 'V-'.uniqid(), 'purchase_price' => 1000, 'sell_price' => 1500]);
        $sup = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'type' => 'supplier', 'name' => 'S']);
        $cus = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'type' => 'customer', 'name' => 'C']);
        $po = app(PurchaseService::class)->createDraft($tenant->id, $w->id, $sup->id, [['product_variant_id' => $v->id, 'quantity' => 5, 'unit_cost' => 1000]]);
        app(PurchaseService::class)->receive($po->id);
        $inv = app(SaleService::class)->checkout($tenant->id, $b->id, $w->id, $cus->id, [['variant_id' => $v->id, 'quantity' => 1, 'unit_price' => 1500]], [['method' => 'cash', 'amount' => 1500]], 'f-'.uniqid());
        $this->assertEquals('fulfilled', $inv->fulfillment_status);
        $this->assertEquals('paid', $inv->payment_status);
    }

    public function test_catalog_crud_isolated(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko Cat', $owner);
        $owner->refresh();
        $this->actingAs($owner)->postJson('/api/v1/categories', ['name' => 'Makanan'], ['X-Tenant-ID' => $tenant->id])->assertCreated();
        $this->actingAs($owner)->postJson('/api/v1/brands', ['name' => 'Indo'], ['X-Tenant-ID' => $tenant->id])->assertCreated();
        $this->actingAs($owner)->postJson('/api/v1/units', ['name' => 'Pcs', 'short_name' => 'pcs'], ['X-Tenant-ID' => $tenant->id])->assertCreated();
        $this->actingAs($owner)->getJson('/api/v1/categories', ['X-Tenant-ID' => $tenant->id])->assertOk();
    }

    public function test_affiliate_commission_no_duplicate(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko Aff', $owner);
        $sub = Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $affUser = User::factory()->create();
        AffiliateController::recordCommission($affUser->id, $tenant->id, $sub->id, 100000, 10);
        AffiliateController::recordCommission($affUser->id, $tenant->id, $sub->id, 100000, 10);
        $this->assertEquals(1, DB::table('affiliate_commissions')->where('subscription_id', $sub->id)->count());
    }
}
