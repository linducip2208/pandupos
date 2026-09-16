<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\SubscriptionService;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_lifecycle_keeps_history(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko Sub', $owner);
        $svc = app(SubscriptionService::class);

        $starter = Plan::where('slug', 'starter')->firstOrFail();
        $growth = Plan::where('slug', 'growth')->firstOrFail();

        $trial = $svc->startTrial($tenant->id, $starter->id);
        $this->assertContains($trial->status, ['trialing', 'active']);

        $active = $svc->activate($trial->id);
        $this->assertEquals('active', $active->status);

        $upgraded = $svc->upgrade($tenant->id, $growth->id);
        $this->assertEquals($growth->id, $upgraded->plan_id);

        $downgraded = $svc->downgrade($tenant->id, $starter->id);
        $this->assertEquals($starter->id, $downgraded->plan_id);

        $renewed = $svc->renew($downgraded->id);
        $this->assertEquals('active', $renewed->status);

        $suspended = $svc->suspend($renewed->id, 'test');
        $this->assertEquals('suspended', $suspended->status);
        $this->assertFalse(app(EntitlementService::class)->allowed($tenant->id, 'pos.access'));

        $reactivated = $svc->activate($suspended->id);
        $this->assertTrue(app(EntitlementService::class)->allowed($tenant->id, 'pos.access'));

        $expired = $svc->expire($reactivated->id);
        $this->assertEquals('expired', $expired->status);

        $cancelled = $svc->cancel($tenant->id, $expired->id);
        $this->assertEquals('cancelled', $cancelled->status);

        // History never destroyed: multiple rows exist.
        $count = Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count();
        $this->assertGreaterThanOrEqual(4, $count);
    }
}
