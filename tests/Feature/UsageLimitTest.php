<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\EntitlementService;
use App\Services\TenantProvisioningService;
use App\Services\UsageLimitService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class UsageLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_has_percent_and_enforcement(): void
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision('Toko Usage', $owner);
        $usage = app(UsageLimitService::class);

        $snap = $usage->snapshot($tenant->id);
        $this->assertArrayHasKey('users', $snap);
        $this->assertArrayHasKey('current', $snap['users']);
        $this->assertArrayHasKey('limit', $snap['users']);
        $this->assertArrayHasKey('percent', $snap['users']);

        // Starter allows 5 users; force exceed by lowering entitlement directly.
        $tenant->activeSubscription->plan->entitlements()->updateOrCreate(['entitlement' => 'users.max'], ['value' => '1']);
        app(EntitlementService::class)->forget($tenant->id);

        $this->expectException(HttpException::class);
        $usage->assertCanCreate($tenant->id, 'users.max');
    }
}
