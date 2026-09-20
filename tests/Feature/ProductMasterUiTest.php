<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Membership;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductMasterUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_can_manage_complete_product_and_archive_without_deleting_history(): void
    {
        Storage::fake('public');
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Product Tenant', $owner);
        $owner->forceFill(['current_tenant_id' => $tenant->id])->save();
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Utama', 'code' => 'UTM']);

        $this->actingAs($owner)->get(route('product-master.index'))->assertOk()->assertSeeText('Produk & Katalog');
        $this->actingAs($owner)->get(route('product-master.create'))->assertOk()->assertSeeText('Varian & atribut');

        $this->actingAs($owner)->post(route('product-master.categories.store'), ['name' => 'Minuman'])->assertRedirect();
        $category = Category::firstOrFail();
        $this->actingAs($owner)->post(route('product-master.categories.store'), ['name' => 'Kopi', 'parent_id' => $category->id])->assertRedirect();
        $this->actingAs($owner)->post(route('product-master.brands.store'), ['name' => 'Pandu'])->assertRedirect();
        $this->actingAs($owner)->post(route('product-master.units.store'), ['name' => 'Pieces', 'short_name' => 'pcs'])->assertRedirect();

        $response = $this->actingAs($owner)->post(route('product-master.store'), [
            'name' => 'Kopi Susu', 'product_type' => 'stock', 'sku' => 'KOPI', 'barcode' => '8990001',
            'category_id' => Category::where('name', 'Kopi')->value('id'), 'brand_id' => Brand::value('id'),
            'unit_id' => Unit::value('id'), 'alert_quantity' => 5, 'tax_rate' => 11, 'tax_method' => 'inclusive',
            'track_inventory' => 1, 'is_active' => 1, 'warehouse_ids' => [$warehouse->id],
            'image' => UploadedFile::fake()->image('kopi.jpg'),
            'variants' => [
                ['name' => 'Regular', 'sku' => 'KOPI-R', 'barcode' => '89900011', 'purchase_price' => 5000, 'sell_price' => 10000, 'attributes_text' => "Ukuran: Regular\nGula: Normal"],
                ['name' => 'Large', 'sku' => 'KOPI-L', 'barcode' => '89900012', 'purchase_price' => 6000, 'sell_price' => 12000, 'attributes_text' => 'Ukuran: Large'],
            ],
        ]);
        $response->assertStatus(302);
        $product = Product::firstOrFail();
        $response->assertRedirect(route('product-master.show', $product));
        $this->assertCount(2, $product->variants);
        // Order-explicit: row order without ORDER BY is driver-dependent.
        $regular = $product->variants->firstWhere('name', 'Regular');
        $this->assertNotNull($regular);
        $this->assertSame('Regular', $regular->attributes['Ukuran']);
        $this->assertDatabaseHas('product_locations', ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'is_active' => true]);
        Storage::disk('public')->assertExists($product->image_path);
        $this->actingAs($owner)->get(route('product-master.show', $product))->assertOk()->assertSeeText('Kopi Susu')->assertSeeText('Regular');

        $variants = $product->variants;
        $this->actingAs($owner)->put(route('product-master.update', $product), [
            'name' => 'Kopi Susu Premium', 'product_type' => 'stock', 'sku' => 'KOPI', 'barcode' => '8990001',
            'category_id' => $product->category_id, 'brand_id' => $product->brand_id, 'unit_id' => $product->unit_id,
            'alert_quantity' => 8, 'tax_rate' => 11, 'tax_method' => 'inclusive', 'track_inventory' => 1, 'is_active' => 1,
            'warehouse_ids' => [$warehouse->id], 'variants' => $variants->map(fn ($variant) => [
                'id' => $variant->id, 'name' => $variant->name, 'sku' => $variant->sku, 'barcode' => $variant->barcode,
                'purchase_price' => $variant->purchase_price, 'sell_price' => $variant->sell_price,
                'attributes_text' => collect($variant->attributes)->map(fn ($value, $key) => $key.': '.$value)->implode("\n"),
            ])->all(),
        ])->assertRedirect(route('product-master.show', $product));
        $this->assertSame('Kopi Susu Premium', $product->fresh()->name);
        $this->assertSame('8.000', $product->fresh()->alert_quantity);

        $brand = Brand::firstOrFail();
        $this->actingAs($owner)->put(route('product-master.brands.update', $brand), ['name' => 'Pandu Coffee'])->assertRedirect();
        $this->actingAs($owner)->post(route('product-master.masters.archive', ['type' => 'brand', 'id' => $brand->id]))->assertRedirect();
        $this->assertDatabaseHas('brands', ['id' => $brand->id, 'name' => 'Pandu Coffee', 'is_active' => false]);

        $this->actingAs($owner)->post(route('product-master.archive', $product))->assertRedirect(route('product-master.index'));
        $this->assertDatabaseHas('products', ['id' => $product->id, 'is_active' => false]);
        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'sku' => 'KOPI-R']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.archived', 'subject_id' => $product->id]);
    }

    public function test_product_master_requires_membership_and_hides_cross_tenant_product(): void
    {
        $this->seed(PlatformSeeder::class);
        $ownerA = User::factory()->create();
        $tenantA = app(TenantProvisioningService::class)->provision('Tenant A', $ownerA);
        $ownerA->forceFill(['current_tenant_id' => $tenantA->id])->save();
        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $tenantA->id, 'name' => 'Rahasia', 'product_type' => 'stock']);
        $ownerB = User::factory()->create();
        $tenantB = app(TenantProvisioningService::class)->provision('Tenant B', $ownerB);
        $ownerB->forceFill(['current_tenant_id' => $tenantB->id])->save();

        $this->actingAs($ownerB)->get(route('product-master.show', $product))->assertForbidden();
        $outsider = User::factory()->create(['current_tenant_id' => $tenantA->id]);
        $this->assertFalse(Membership::where('user_id', $outsider->id)->exists());
        $this->actingAs($outsider)->get(route('product-master.index'))->assertForbidden();
    }
}
