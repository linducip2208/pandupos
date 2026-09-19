<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sensitive admin hardening: fresh password confirmation is required before
 * sensitive platform mutations, and impersonated sessions can never confirm
 * or perform those writes.
 */
class SensitiveAdminReauthTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 's3cret-confirm!';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlatformSeeder::class);
        $this->admin = User::factory()->create(['is_platform_admin' => true, 'password' => self::PASSWORD]);
        $this->admin->assignRole('platform-admin');
    }

    private function provisionedTenant(): array
    {
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko Reauth', $owner);

        return [$tenant, $owner->refresh()];
    }

    public function test_sensitive_mutation_redirects_to_confirmation_without_fresh_auth(): void
    {
        [$tenant, $owner] = $this->provisionedTenant();

        $this->actingAs($this->admin)
            ->post("/platform/tenants/{$tenant->id}/impersonate", ['target_user_id' => $owner->id])
            ->assertRedirect(route('reauth.confirm'));

        // Nothing happened: no session row, no audit, read-only routes still fine.
        $this->assertDatabaseMissing('impersonation_sessions', ['tenant_id' => $tenant->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'impersonation.started', 'tenant_id' => $tenant->id]);
        $this->actingAs($this->admin)->get('/platform/dashboard')->assertOk();
        $this->actingAs($this->admin)->get(route('reauth.confirm'))->assertOk();
    }

    public function test_confirmation_rejects_wrong_password(): void
    {
        $this->actingAs($this->admin)
            ->post(route('reauth.confirm.store'), ['password' => 'wrong-password'])
            ->assertSessionHasErrors('password')
            ->assertSessionMissing('sensitive_auth_at');

        [$tenant, $owner] = $this->provisionedTenant();
        $this->actingAs($this->admin)
            ->post("/platform/tenants/{$tenant->id}/impersonate", ['target_user_id' => $owner->id])
            ->assertRedirect(route('reauth.confirm'));
    }

    public function test_correct_password_confirms_unlocks_action_and_is_audited_without_secrets(): void
    {
        [$tenant, $owner] = $this->provisionedTenant();

        $this->actingAs($this->admin)
            ->post(route('reauth.confirm.store'), ['password' => self::PASSWORD])
            ->assertRedirect('/platform/dashboard')
            ->assertSessionHas('sensitive_auth_at');

        $row = AuditLog::withoutGlobalScopes()->where('action', 'auth.sensitive_reconfirmed')->first();
        $this->assertNotNull($row);
        $this->assertSame($this->admin->id, $row->actor_id);
        $this->assertNull($row->tenant_id);
        $this->assertStringNotContainsString('s3cret', json_encode($row->toArray()));

        // A freshly confirmed session may perform the sensitive mutation.
        $this->withSession(['sensitive_auth_at' => time()])->actingAs($this->admin)
            ->post("/platform/tenants/{$tenant->id}/impersonate", ['target_user_id' => $owner->id])
            ->assertRedirect('/dashboard');
        $this->assertDatabaseHas('impersonation_sessions', ['tenant_id' => $tenant->id, 'platform_user_id' => $this->admin->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'impersonation.started', 'tenant_id' => $tenant->id]);
    }

    public function test_stale_confirmation_forces_reconfirm(): void
    {
        [$tenant, $owner] = $this->provisionedTenant();

        $this->withSession(['sensitive_auth_at' => time() - 3600])->actingAs($this->admin)
            ->post("/platform/tenants/{$tenant->id}/impersonate", ['target_user_id' => $owner->id])
            ->assertRedirect(route('reauth.confirm'));

        $this->assertDatabaseMissing('impersonation_sessions', ['tenant_id' => $tenant->id]);
    }

    public function test_impersonating_session_cannot_perform_sensitive_writes(): void
    {
        [$tenant] = $this->provisionedTenant();
        $flag = ['session_id' => 999, 'tenant_id' => $tenant->id, 'tenant_name' => $tenant->name];

        // Even a platform admin carrying the impersonation flag is blocked.
        $this->withSession(['impersonating' => $flag, 'sensitive_auth_at' => time()])->actingAs($this->admin)
            ->post("/platform/tenants/{$tenant->id}/suspend")
            ->assertForbidden();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'tenant.suspended', 'tenant_id' => $tenant->id]);
    }

    public function test_impersonating_session_cannot_open_confirm_page(): void
    {
        [$tenant] = $this->provisionedTenant();
        $flag = ['session_id' => 999, 'tenant_id' => $tenant->id, 'tenant_name' => $tenant->name];

        $this->withSession(['impersonating' => $flag])->actingAs($this->admin)
            ->get(route('reauth.confirm'))
            ->assertForbidden();
    }

    public function test_impersonating_session_cannot_confirm(): void
    {
        [$tenant] = $this->provisionedTenant();
        $flag = ['session_id' => 999, 'tenant_id' => $tenant->id, 'tenant_name' => $tenant->name];

        $this->withSession(['impersonating' => $flag])->actingAs($this->admin)
            ->post(route('reauth.confirm.store'), ['password' => self::PASSWORD])
            ->assertForbidden();

        // Rejected before any state change: no confirmation audit event exists.
        $this->assertDatabaseMissing('audit_logs', ['action' => 'auth.sensitive_reconfirmed']);
    }

    public function test_announcement_send_requires_confirmation(): void
    {
        $this->provisionedTenant();
        $announcement = Announcement::create([
            'subject' => 'Maintenance', 'body' => 'Downtime', 'audience' => 'trial', 'channel' => 'in-app',
        ]);

        $this->actingAs($this->admin)
            ->post("/platform/announcements/{$announcement->id}/send")
            ->assertRedirect(route('reauth.confirm'));
        $this->assertSame(0, DB::table('announcement_deliveries')->where('announcement_id', $announcement->id)->count());

        $this->withSession(['sensitive_auth_at' => time()])->actingAs($this->admin)
            ->post("/platform/announcements/{$announcement->id}/send")
            ->assertRedirect();
        $this->assertSame(1, DB::table('announcement_deliveries')->where('announcement_id', $announcement->id)->count());
    }

    public function test_json_callers_receive_423_with_confirm_url(): void
    {
        [$tenant] = $this->provisionedTenant();

        $this->actingAs($this->admin)
            ->postJson("/platform/tenants/{$tenant->id}/suspend")
            ->assertStatus(423)
            ->assertJsonPath('confirm_url', route('reauth.confirm'));
    }
}
