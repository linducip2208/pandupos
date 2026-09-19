<?php

namespace Tests\Feature;

use App\Models\TenantDomain;
use App\Models\User;
use App\Services\TenantDomainService;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DomainSafetyTest extends TestCase
{
    use RefreshDatabase;

    private TenantDomainService $domains;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlatformSeeder::class);
        $this->domains = app(TenantDomainService::class);
    }

    private function tenant(string $name): array
    {
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision($name, $owner);

        return [$tenant, $owner->refresh()];
    }

    public function test_domain_starts_pending_and_only_verifies_with_secret_token(): void
    {
        [$tenant] = $this->tenant('Toko TL1');
        $domain = $this->domains->createForTenant($tenant->id, 'toko-tl1.example', 1);

        $this->assertSame('pending', $domain->status);
        $this->assertNull($domain->verified_at);
        $this->assertTrue(Str::length($domain->verification_token) === 64);
        $this->assertNotSame($domain->domain, $domain->verification_token);

        // Unknown / forged token can never verify ownership.
        $this->assertNull($this->domains->verifyByToken(Str::random(64)));

        $verified = $this->domains->verifyByToken($domain->verification_token);
        $this->assertSame('verified', $verified->status);
        $this->assertNotNull($verified->verified_at);
        $this->assertTrue($verified->is_primary); // first verified domain becomes primary

        // Re-verifying with the same token is idempotent: no state reset, still one primary.
        $again = $this->domains->verifyByToken($domain->verification_token);
        $this->assertSame($verified->id, $again->id);
        $this->assertSame('verified', $again->status);
        $this->assertSame(1, TenantDomain::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('is_primary', true)->count());
    }

    public function test_unverified_domain_never_resolves_by_host(): void
    {
        [$tenant] = $this->tenant('Toko TL2');
        $domain = $this->domains->createForTenant($tenant->id, 'toko-2.example', 1);

        foreach (['toko-2.example', 'Toko-2.example', 'toko-2.example:443', 'https://toko-2.example', 'toko-2.example.'] as $host) {
            $this->assertNull($this->domains->resolveByHost($host), "pending host must not resolve: {$host}");
        }
    }

    public function test_safe_host_resolution_exact_match_verified_only(): void
    {
        [$a] = $this->tenant('Toko A');
        [$b] = $this->tenant('Toko B');
        $aDomain = $this->domains->createForTenant($a->id, 'shop-a.example', 1);
        $this->domains->verifyByToken($aDomain->verification_token);
        $this->domains->createForTenant($b->id, 'shop-b.example', 1); // never verified

        // Exact host of verified domain resolves to its owning tenant.
        $resolved = $this->domains->resolveByHost('shop-a.example');
        $this->assertNotNull($resolved);
        $this->assertSame($aDomain->id, $resolved->id);
        $this->assertSame($a->id, $resolved->tenant_id);

        // Unverified sibling domain never resolves.
        $this->assertNull($this->domains->resolveByHost('shop-b.example'));

        // Hijack attempts: containing/prefix/similar hosts must NOT match.
        foreach (['shop-a.example.evil.net', 'evilsquer.xyz', 'xshop-a.example', 'shop-a.example.com', 'shop-a.example\nX-Host: evil', '" onerror="alert(1)', '127.0.0.1', 'INVALID_HOST??'] as $host) {
            $this->assertNull($this->domains->resolveByHost($host), "unsafe host must not resolve: {$host}");
        }

        // Scheme, port and case are normalized but still exact-match the stored FQDN.
        $this->assertSame($aDomain->id, $this->domains->resolveByHost('HTTP://SHOP-A.EXAMPLE:8443')?->id);
    }

    public function test_domain_uniqueness_and_duplicate_claim_forbidden(): void
    {
        [$a] = $this->tenant('Toko A2');
        [$b] = $this->tenant('Toko B2');
        $this->domains->createForTenant($a->id, 'one-owner.example', 1);

        $this->assertThrows(fn () => $this->domains->createForTenant($b->id, 'one-owner.example', 1), \Throwable::class);
        $this->assertSame(1, TenantDomain::withoutGlobalScopes()->where('domain', 'one-owner.example')->count());
    }

    public function test_primary_is_only_verified_and_promotion_switches_uniquely(): void
    {
        [$tenant] = $this->tenant('Toko TL3');
        $first = $this->domains->createForTenant($tenant->id, 'primary-1.example', 1);
        $second = $this->domains->createForTenant($tenant->id, 'primary-2.example', 1);

        // Unverified domain cannot be promoted to primary.
        $this->assertThrows(fn () => $this->domains->setPrimary($second), HttpException::class);

        $this->domains->verifyByToken($second->verification_token);
        $this->domains->setPrimary($second->refresh());
        $this->assertSame(1, TenantDomain::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('is_primary', true)->count());
        $this->assertSame($second->id, TenantDomain::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('is_primary', true)->value('id'));
    }

    public function test_platform_domain_store_and_public_verify_route(): void
    {
        [$tenant] = $this->tenant('Toko TL4');
        $admin = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($admin)->post('/platform/domains', ['tenant_id' => $tenant->id, 'domain' => 'route-v.example'])
            ->assertRedirect();
        $stored = TenantDomain::withoutGlobalScopes()->where('domain', 'route-v.example')->first();
        $this->assertNotNull($stored);
        $this->assertNotNull($stored->verification_token);

        // Full ownership handshake through the public route (token = proof of ownership).
        $this->get("/verify-tenant-domain/{$stored->verification_token}")
            ->assertOk()
            ->assertSee('route-v.example');

        $this->get('/verify-tenant-domain/'.Str::random(64))->assertNotFound();
    }
}
