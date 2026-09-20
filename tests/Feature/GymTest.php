<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\GymMember;
use App\Models\GymMembership;
use App\Models\GymPackage;
use App\Models\Membership;
use App\Models\User;
use App\Services\GymService;
use App\Services\ModuleManager;
use App\Services\TenantProvisioningService;
use App\Support\TenantContext;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Gym module: packages, members, single-active subscriptions, capped
 * check-ins with lazy expiry, payments, isolation, RBAC and API.
 */
class GymTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $name = 'Toko Gym', bool $enableGym = true): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision($name, $owner);
        $owner->refresh();
        if ($enableGym) {
            app(ModuleManager::class)->enable($tenant->id, 'gym');
        }
        TenantContext::setId($tenant->id);

        return [$tenant, $owner];
    }

    public function test_module_gates_workspace_and_api(): void
    {
        [$tenant, $owner] = $this->context('Toko Gym Gate', false);

        $this->actingAs($owner)->get('/gym')->assertForbidden();
        $this->actingAs($owner)->getJson('/api/v1/gym/memberships', ['X-Tenant-ID' => $tenant->id])->assertForbidden();

        app(ModuleManager::class)->enable($tenant->id, 'gym');
        $this->actingAs($owner)->get('/gym')->assertOk()->assertSeeText('Langganan');
    }

    public function test_subscribe_enforces_single_active_and_checkin_caps(): void
    {
        [$tenant, $owner] = $this->context();
        $svc = app(GymService::class);
        $package = $svc->createPackage($tenant->id, ['name' => 'Bulanan', 'duration_days' => 30, 'price' => 300000, 'visits_limit' => 2], $owner->id);
        $member = $svc->registerMember($tenant->id, ['name' => 'Fit'], $owner->id);
        $this->assertSame('GYM-0001', $member->code);

        $sub = $svc->subscribe($tenant->id, $member->id, $package->id, now()->toDateString(), $owner->id);
        $this->assertSame(date('Y-m-d', strtotime('+30 days')), $sub->ends_on->toDateString());
        try {
            $svc->subscribe($tenant->id, $member->id, $package->id, now()->toDateString(), $owner->id);
            $this->fail('Double active subscription must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $svc->checkIn($sub, null, $owner->id);
        $svc->checkIn($sub->refresh(), null, $owner->id);
        try {
            $svc->checkIn($sub->refresh(), null, $owner->id);
            $this->fail('Check-in beyond quota must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_expired_membership_blocks_checkin_and_renews_cleanly(): void
    {
        [$tenant, $owner] = $this->context();
        $svc = app(GymService::class);
        $package = $svc->createPackage($tenant->id, ['name' => 'Harian', 'duration_days' => 1, 'price' => 50000], $owner->id);
        $member = $svc->registerMember($tenant->id, ['name' => 'Lama'], $owner->id);

        $sub = $svc->subscribe($tenant->id, $member->id, $package->id, now()->subDays(5)->toDateString(), $owner->id);
        try {
            $svc->checkIn($sub, null, $owner->id);
            $this->fail('Expired membership must refuse check-in.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertSame('expired', $sub->refresh()->status);

        // Renewal is a new subscription row: history preserved.
        $renewed = $svc->subscribe($tenant->id, $member->id, $package->id, now()->toDateString(), $owner->id);
        $this->assertSame('active', $renewed->status);
        $this->assertSame(2, GymMembership::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $svc->checkIn($renewed, null, $owner->id);
    }

    public function test_payments_and_cancel_rules(): void
    {
        [$tenant, $owner] = $this->context();
        $svc = app(GymService::class);
        $package = $svc->createPackage($tenant->id, ['name' => 'Tahunan', 'duration_days' => 365, 'price' => 3000000], $owner->id);
        $member = $svc->registerMember($tenant->id, ['name' => 'Bayar'], $owner->id);
        $sub = $svc->subscribe($tenant->id, $member->id, $package->id, now()->toDateString(), $owner->id);

        try {
            $svc->recordPayment($sub, 4000000, $owner->id);
            $this->fail('Overpayment must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $svc->recordPayment($sub, 1000000, $owner->id);
        $this->assertSame(2000000.0, (float) $sub->refresh()->balance);
        $this->assertSame('cancelled', $svc->cancel($sub->refresh(), $owner->id)->status);
        try {
            $svc->recordPayment($sub->refresh(), 1000, $owner->id);
            $this->fail('Paying a cancelled membership must be refused.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_tenant_isolation_and_rbac(): void
    {
        [$tenantA] = $this->context('Toko Gym A');
        [$tenantB, $ownerB] = $this->context('Toko Gym B');
        $memberA = app(GymService::class)->registerMember($tenantA->id, ['name' => 'Rahasia A'], null);

        TenantContext::setId($tenantB->id);
        $this->actingAs($ownerB)->get('/gym')->assertOk()->assertDontSee('Rahasia A');
        $this->actingAs($ownerB)->post('/gym/memberships/999999/checkin')->assertNotFound();

        $member = User::factory()->create();
        Membership::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id, 'user_id' => $member->id,
            'branch_id' => Branch::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->value('id'),
            'is_active' => true,
        ]);
        $member->forceFill(['current_tenant_id' => $tenantB->id])->save();
        $this->actingAs($member)->get('/gym')->assertForbidden();
        $member->givePermissionTo('gym.view');
        $this->actingAs($member)->get('/gym')->assertOk();
        $this->actingAs($member)->post('/gym/members', ['name' => 'X'])->assertForbidden();
    }

    public function test_api_lifecycle(): void
    {
        [$tenant, $owner] = $this->context();
        $headers = ['X-Tenant-ID' => $tenant->id];
        $svc = app(GymService::class);
        $package = $svc->createPackage($tenant->id, ['name' => 'API Pack', 'duration_days' => 30, 'price' => 200000], $owner->id);
        $member = $svc->registerMember($tenant->id, ['name' => 'API Member'], $owner->id);

        $created = $this->actingAs($owner)->postJson('/api/v1/gym/subscribe', [
            'member_id' => $member->id, 'package_id' => $package->id, 'starts_on' => now()->toDateString(),
        ], $headers)->assertCreated();
        $id = $created->json('data.id');

        $this->actingAs($owner)->postJson("/api/v1/gym/memberships/{$id}/transition", ['action' => 'checkin'], $headers)->assertOk();
        $this->actingAs($owner)->postJson("/api/v1/gym/memberships/{$id}/transition", ['action' => 'pay', 'amount' => 200000], $headers)->assertOk()->assertJsonPath('data.balance', '0.00');
        $this->actingAs($owner)->getJson('/api/v1/gym/memberships', $headers)->assertOk();
    }

    public function test_workspace_flow(): void
    {
        [$tenant, $owner] = $this->context();

        $this->actingAs($owner)->post('/gym/packages', ['name' => 'Web Pack', 'duration_days' => 30, 'price' => 150000])->assertRedirect();
        $this->actingAs($owner)->post('/gym/members', ['name' => 'Web Member'])->assertRedirect();
        $member = GymMember::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $package = GymPackage::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->actingAs($owner)->post('/gym/subscribe', ['member_id' => $member->id, 'package_id' => $package->id, 'starts_on' => now()->toDateString()])->assertRedirect();
        $sub = GymMembership::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->actingAs($owner)->post("/gym/memberships/{$sub->id}/checkin")->assertRedirect();
        $this->actingAs($owner)->post("/gym/memberships/{$sub->id}/pay", ['amount' => 150000])->assertRedirect();
        $this->assertSame(0.0, (float) $sub->refresh()->balance);
        $this->actingAs($owner)->get('/gym')->assertOk()->assertSeeText('Web Member');
    }
}
