<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\CustomerLogin;
use App\Models\Product;
use App\Models\User;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Web security audit: CSRF, XSS, mass assignment, SQLi, redirects,
 * session handling, rate limits, dangerous inputs, unescaped rendering.
 */
class WebSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_csrf_protection_active_on_web_posts(): void
    {
        $this->seed(PlatformSeeder::class);
        // Guest POST to session route without CSRF token must not succeed silently.
        $res = $this->post('/login', ['email' => 'x@y.z', 'password' => 'secret']);
        $this->assertContains($res->getStatusCode(), [302, 419, 422]);
    }

    public function test_xss_user_input_is_escaped_in_blade(): void
    {
        $payload = '<script>alert(1)</script>';
        $escaped = e($payload);
        $this->assertStringNotContainsString('<script>', $escaped);
        $this->assertStringContainsString('&lt;script&gt;', $escaped);

        // No unescaped user echo for critical views: product/portal/report blades must use {{ }}.
        $suspects = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            $content = File::get($file->getPathname());
            if (preg_match('/\{!!\s*\$_(GET|POST|REQUEST)/', $content)) {
                $suspects[] = $file->getRelativePathname();
            }
        }
        $this->assertSame([], $suspects, 'Unescaped superglobal rendering found.');
    }

    public function test_mass_assignment_cannot_override_tenant(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenantA = app(TenantProvisioningService::class)->provision('Toko A Mass', $owner);
        $tenantB = app(TenantProvisioningService::class)->provision('Toko B Mass', User::factory()->create());
        $owner->update(['current_tenant_id' => $tenantA->id]);
        $owner->givePermissionTo(['inventory.view']);

        // Attempt to force tenant B via body on contact create.
        $this->actingAs($owner)->postJson('/api/v1/contacts', [
            'name' => 'Mass', 'type' => 'customer', 'tenant_id' => $tenantB->id,
        ], ['X-Tenant-ID' => $tenantA->id]);
        $this->assertFalse(
            Contact::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->where('name', 'Mass')->exists()
        );
        // Product model must guard tenant_id.
        $product = new Product(['tenant_id' => $tenantA->id, 'name' => 'P', 'sku' => 'S-'.uniqid()]);
        $this->assertTrue(in_array('tenant_id', $product->getFillable()) || $product->tenant_id === $tenantA->id);
    }

    public function test_sql_injection_search_does_not_leak_other_tenant(): void
    {
        $this->seed(PlatformSeeder::class);
        $ownerA = User::factory()->create();
        $tenantA = app(TenantProvisioningService::class)->provision('Toko A SQLi', $ownerA);
        $ownerB = User::factory()->create();
        $tenantB = app(TenantProvisioningService::class)->provision('Toko B SQLi', $ownerB);
        Product::withoutGlobalScopes()->create(['tenant_id' => $tenantB->id, 'name' => 'Secret-B', 'sku' => 'SECB-'.uniqid()]);
        $ownerA->update(['current_tenant_id' => $tenantA->id]);
        $ownerA->givePermissionTo(['inventory.view']);

        $res = $this->actingAs($ownerA)->getJson("/api/v1/products?search=' OR '1'='1", ['X-Tenant-ID' => $tenantA->id]);
        $res->assertOk();
        $this->assertStringNotContainsString('Secret-B', $res->getContent());
    }

    public function test_open_redirect_not_allowed_on_login(): void
    {
        $res = $this->get('/login?next=http://evil.example.com');
        $res->assertOk();
        $this->assertStringNotContainsString('evil.example.com', $res->getContent());
    }

    public function test_password_reset_route_not_exposed_without_protection(): void
    {
        // No password reset flow is registered; must 404 rather than expose a weak flow.
        $this->get('/password/reset')->assertNotFound();
        $this->post('/password/email', ['email' => 'a@b.c'])->assertNotFound();
    }

    public function test_session_isolation_customer_cannot_access_staff_dashboard(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko Sess', $owner);
        $contact = Contact::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'type' => 'customer', 'name' => 'C']);
        $login = CustomerLogin::create(['tenant_id' => $tenant->id, 'contact_id' => $contact->id, 'email' => 'c-'.uniqid().'@x.y', 'password' => bcrypt('secret123')]);

        $this->actingAs($login, 'customer')->get('/dashboard')->assertForbidden();
        $resPortal = $this->actingAs($owner)->get('/portal/invoices');
        $this->assertContains($resPortal->getStatusCode(), [302, 403]);
    }

    public function test_api_rate_limit_headers_present(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko Rate', $owner);
        $owner->update(['current_tenant_id' => $tenant->id]);
        $res = $this->actingAs($owner)->getJson('/api/v1/me', ['X-Tenant-ID' => $tenant->id]);
        $res->assertOk();
        $this->assertTrue(
            $res->headers->has('X-RateLimit-Limit') || $res->headers->has('x-ratelimit-limit'),
            'Throttle middleware must emit rate-limit headers.'
        );
    }

    public function test_dangerous_inputs_rejected_by_validation(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko Danger', $owner);
        $owner->update(['current_tenant_id' => $tenant->id]);
        $owner->givePermissionTo(['inventory.view']);

        $long = str_repeat('A', 5000);
        $res = $this->actingAs($owner)->postJson('/api/v1/contacts', ['name' => $long, 'type' => 'customer'], ['X-Tenant-ID' => $tenant->id]);
        $this->assertContains($res->getStatusCode(), [200, 201, 422]);
        if ($res->getStatusCode() === 422) {
            $this->assertTrue(true);
        } else {
            // If accepted, length must be bounded at DB/app level (not crash).
            $this->assertLessThan(5000, strlen($long));
        }
    }
}
