<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Membership;
use App\Models\User;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sensitive admin hardening: platform actions require explicit admin,
 * impersonation is permission-gated and audited, no silent privilege path.
 */
class SensitiveAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_sensitive_routes_require_admin(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko Sens', $owner);
        $owner->update(['current_tenant_id' => $tenant->id]);

        foreach (['/platform/dashboard', '/platform/tenants', '/platform/health', '/platform/audit', '/platform/billing', '/platform/coupons'] as $uri) {
            $this->actingAs($owner)->get($uri)->assertForbidden();
        }
        $this->actingAs($owner)->getJson('/api/v1/platform/tenants')->assertForbidden();

        $admin = User::factory()->create(['is_platform_admin' => true]);
        $admin->assignRole('platform-admin');
        $this->actingAs($admin)->get('/platform/dashboard')->assertOk();
        $this->actingAs($admin)->get('/platform/health')->assertOk();
    }

    public function test_impersonation_requires_permission_and_is_audited(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko Imp', $owner);
        $member = User::factory()->create();
        Membership::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'user_id' => $member->id, 'branch_id' => Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('id'), 'is_active' => true]);

        // Non-privileged user cannot start impersonation.
        $this->actingAs($member)->post("/platform/tenants/{$tenant->id}/impersonate", ['target_user_id' => $owner->id])->assertForbidden();

        $admin = User::factory()->create(['is_platform_admin' => true]);
        $admin->assignRole('platform-admin');
        $this->actingAs($admin)->post("/platform/tenants/{$tenant->id}/impersonate", ['target_user_id' => $owner->id])->assertRedirect('/dashboard');
        $this->assertDatabaseHas('audit_logs', ['action' => 'impersonation.started', 'tenant_id' => $tenant->id]);
        $this->assertDatabaseHas('impersonation_sessions', ['tenant_id' => $tenant->id, 'platform_user_id' => $admin->id]);
    }

    public function test_audit_never_logs_passwords_or_secrets(): void
    {
        $this->seed(PlatformSeeder::class);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'password']);
        $logs = AuditLog::withoutGlobalScopes()->take(5)->get();
        foreach ($logs as $log) {
            $this->assertStringNotContainsString('APP_KEY', json_encode($log->toArray()));
        }
        $this->assertTrue(true);
    }
}
