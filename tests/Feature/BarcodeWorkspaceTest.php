<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BarcodeWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_can_manage_scale_profile_parse_and_print_scannable_labels(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Barcode Tenant', $owner);
        $owner->forceFill(['current_tenant_id' => $tenant->id])->save();
        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Apel', 'product_type' => 'stock']);
        $variant = ProductVariant::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => 'Merah', 'sku' => '12345',
            'barcode' => '8991234567890', 'purchase_price' => 10000, 'sell_price' => 15000,
        ]);

        $this->actingAs($owner)->get(route('barcodes.index'))->assertOk()->assertSeeText('Profil barcode timbangan');
        $this->actingAs($owner)->post(route('barcodes.profiles.store'), [
            'name' => 'Scale Weight', 'prefix' => '20', 'total_length' => 13,
            'item_start' => 2, 'item_length' => 5, 'value_start' => 7, 'value_length' => 5,
            'value_type' => 'weight', 'decimal_places' => 3, 'is_active' => 1,
        ])->assertRedirect();
        $this->assertDatabaseHas('barcode_profiles', ['tenant_id' => $tenant->id, 'prefix' => '20', 'is_active' => true]);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'barcode.profile.created']);

        $this->actingAs($owner)->get(route('barcodes.labels', [
            'variants' => [$variant->id], 'quantity' => 2, 'template' => 'a4',
        ]))->assertOk()->assertSee('8991234567890')->assertSee('<svg', false)->assertSeeText('Cetak label');
    }

    public function test_barcode_workspace_rejects_cross_tenant_label_id(): void
    {
        $this->seed(PlatformSeeder::class);
        $ownerA = User::factory()->create();
        $tenantA = app(TenantProvisioningService::class)->provision('A', $ownerA);
        $ownerA->forceFill(['current_tenant_id' => $tenantA->id])->save();
        $tenantB = app(TenantProvisioningService::class)->provision('B', User::factory()->create());
        $product = Product::withoutGlobalScopes()->create(['tenant_id' => $tenantB->id, 'name' => 'Foreign']);
        $variant = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenantB->id, 'product_id' => $product->id, 'name' => 'Foreign', 'barcode' => '123456']);

        $this->actingAs($ownerA)->get(route('barcodes.labels', [
            'variants' => [$variant->id], 'quantity' => 1, 'template' => 'thermal',
        ]))->assertStatus(422);
    }
}
