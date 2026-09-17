<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BundleWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_can_configure_bundle_components_with_audit(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Bundle Tenant', $owner);
        $owner->forceFill(['current_tenant_id' => $tenant->id])->save();
        $bundle = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Paket Hemat', 'product_type' => 'bundle']);
        ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $bundle->id, 'name' => 'Default', 'sku' => 'PAKET']);
        $componentProduct = Product::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Burger', 'product_type' => 'stock']);
        $component = ProductVariant::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'product_id' => $componentProduct->id, 'name' => 'Default', 'sku' => 'BRG']);

        $this->actingAs($owner)->get(route('bundles.index'))->assertOk()->assertSeeText('Paket Hemat');
        $this->actingAs($owner)->post(route('bundles.sync', $bundle), ['items' => [['component_variant_id' => $component->id, 'quantity' => 2]]])->assertRedirect();

        $this->assertDatabaseHas('bundle_items', ['tenant_id' => $tenant->id, 'bundle_product_id' => $bundle->id, 'component_variant_id' => $component->id, 'quantity' => 2]);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'bundle.components.synced', 'subject_id' => $bundle->id]);
    }
}
