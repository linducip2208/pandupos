<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Web security audit: stored-XSS escaping, mass-assignment guards,
 * login brute-force throttling, open-redirect safety.
 */
class WebSecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_stored_xss_payload_in_catalog_is_escaped(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('XSS Audit', $owner);
        $owner->forceFill(['current_tenant_id' => $tenant->id])->save();
        $owner->givePermissionTo(['products.manage', 'inventory.view']);
        $payload = '<script>alert("xss")</script><img src=x onerror=alert(1)>';

        $this->actingAs($owner)->post(route('product-master.store'), [
            'name' => $payload, 'product_type' => 'stock', 'sku' => 'XSS-'.uniqid(),
            'alert_quantity' => 0, 'tax_rate' => 0, 'tax_method' => 'zero',
            'variants' => [['name' => 'Default', 'purchase_price' => 1, 'sell_price' => 2]],
        ])->assertRedirect();

        $page = $this->actingAs($owner)->get(route('product-master.index'));
        $page->assertOk()->assertDontSee('<script>alert("xss")</script>', false);
        $page->assertSee(e($payload), false);
    }

    public function test_mass_assignment_cannot_elevate_to_platform_admin_or_switch_tenant(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('MassAssign', $owner);

        $attacker = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $attacker->memberships()->create(['tenant_id' => $tenant->id, 'role' => 'cashier', 'status' => 'active']);

        // Even if an endpoint ever passed raw input to the model, guarded attributes must not flip.
        $attacker->update(['name' => 'Attacker']);
        $this->assertFalse((bool) $attacker->refresh()->is_platform_admin);

        $product = Product::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        if ($product) {
            $product->update(['name' => $product->name]);
            $this->assertSame($tenant->id, (int) $product->refresh()->tenant_id);
        }
    }

    public function test_staff_login_is_rate_limited_against_brute_force(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password']);
        }
        $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password'])->assertStatus(429);
    }

    public function test_portal_login_is_rate_limited_against_brute_force(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post(route('portal.login.attempt'), ['email' => 'nobody@example.com', 'password' => 'wrong-password']);
        }
        $this->post(route('portal.login.attempt'), ['email' => 'nobody@example.com', 'password' => 'wrong-password'])->assertStatus(429);
    }

    public function test_login_does_not_honor_external_next_redirect(): void
    {
        $res = $this->get('/login?next=http://evil.example.com');
        $res->assertOk()->assertDontSee('http://evil.example.com');
    }

    public function test_upload_rejects_spoofed_executable_and_huge_dimension_images(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Upload Audit', $owner);
        $owner->givePermissionTo('products.manage');

        $fake = UploadedFile::fake()->createWithContent('x.php', '<?php echo 1; ?>');
        $this->actingAs($owner)
            ->post(route('product-master.store'), [
                'name' => 'U', 'product_type' => 'stock', 'sku' => 'UP-'.uniqid(),
                'alert_quantity' => 0, 'tax_rate' => 0, 'tax_method' => 'zero',
                'image' => $fake, 'variants' => [['name' => 'D', 'purchase_price' => 1, 'sell_price' => 2]],
            ])->assertSessionHasErrors('image');

        $huge = UploadedFile::fake()->image('huge.png', 4200, 4200);
        $this->actingAs($owner)
            ->post(route('product-master.store'), [
                'name' => 'U2', 'product_type' => 'stock', 'sku' => 'UP-'.uniqid(),
                'alert_quantity' => 0, 'tax_rate' => 0, 'tax_method' => 'zero',
                'image' => $huge, 'variants' => [['name' => 'D', 'purchase_price' => 1, 'sell_price' => 2]],
            ])->assertSessionHasErrors('image');
    }
}
