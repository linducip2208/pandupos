<?php

namespace Tests\Feature;

use App\Http\Controllers\Platform\AffiliateController;
use App\Models\Announcement;
use App\Models\Coupon;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CouponService;
use App\Services\TenantProvisioningService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CouponAffiliateAnnouncementSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function provisionedTenant(string $name = 'Toko SAFE'): array
    {
        $this->seed(PlatformSeeder::class);
        $owner = User::factory()->create();
        $tenant = app(TenantProvisioningService::class)->provision($name, $owner);

        return [$tenant, $owner->refresh()];
    }

    public function test_coupon_redemption_is_atomic_and_caps_hold(): void
    {
        [$tenant, $owner] = $this->provisionedTenant();
        $plan = Plan::where('slug', 'growth')->first() ?? Plan::first();
        $coupon = Coupon::create([
            'code' => 'WELCOME10', 'discount_type' => 'percentage', 'discount_value' => 10,
            'max_redemptions' => 1, 'per_tenant_limit' => 1, 'is_active' => true,
        ]);
        $service = app(CouponService::class);

        [$locked, $discount] = $service->redeemCode('WELCOME10', $tenant->id, $plan->id, 100000);
        $this->assertSame(10000.0, (float) $discount);
        $this->assertSame(1, Coupon::withoutGlobalScopes()->find($locked->id)->redemptions()->count());

        // Same tenant re-use rejected even though max_redemptions still allows one.
        $this->assertThrows(fn () => $service->redeemCode('WELCOME10', $tenant->id, $plan->id, 100000), HttpException::class);

        // New tenant hits the global cap: rejected.
        $owner2 = User::factory()->create();
        $tenant2 = app(TenantProvisioningService::class)->provision('Toko SAFE 2', $owner2);
        $this->assertThrows(fn () => $service->redeemCode('WELCOME10', $tenant2->id, $plan->id, 100000), HttpException::class);

        // Expired coupon cannot be claimed at all.
        Coupon::create([
            'code' => 'EXPIRED', 'discount_type' => 'fixed', 'discount_value' => 5000,
            'valid_until' => now()->subDay(), 'per_tenant_limit' => 1, 'is_active' => true,
        ])->refresh();
        $this->assertThrows(fn () => $service->redeemCode('EXPIRED', $tenant->id, $plan->id, 100000), HttpException::class);
    }

    public function test_affiliate_commission_records_once_per_subscription(): void
    {
        [$tenant, $owner] = $this->provisionedTenant();
        $sub = Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $affUser = User::factory()->create();

        AffiliateController::recordCommission($affUser->id, $tenant->id, $sub->id, 100000, 10);
        AffiliateController::recordCommission($affUser->id, $tenant->id, $sub->id, 100000, 10);

        $this->assertSame(1, DB::table('affiliate_commissions')->where('subscription_id', $sub->id)->count());
        $row = DB::table('affiliate_commissions')->where('subscription_id', $sub->id)->first();
        $this->assertSame('10000.00', number_format((float) $row->commission_amount, 2, '.', ''));
    }

    public function test_announcement_deliveries_are_queued_then_drained_after_send(): void
    {
        [$trialTenant, $owner] = $this->provisionedTenant();
        Tenant::where('id', $trialTenant->id)->update(['status' => 'trial']);

        $admin = User::factory()->create(['is_platform_admin' => true]);
        $announcement = Announcement::create([
            'subject' => 'Maintenance', 'body' => 'Downtime', 'audience' => 'trial', 'channel' => 'in-app',
        ]);

        // Announcement broadcast is a sensitive mutation: fresh password confirmation required.
        $this->withSession(['sensitive_auth_at' => time()])->actingAs($admin)->post("/platform/announcements/{$announcement->id}/send")
            ->assertRedirect()
            ->assertSessionHas('status');
        $this->assertSame(1, DB::table('announcement_deliveries')->where('announcement_id', $announcement->id)->count());

        // Non-admin can never trigger delivery broadcasting.
        $this->actingAs($owner)->post("/platform/announcements/{$announcement->id}/send")->assertForbidden();

        $this->assertSame('queued', DB::table('announcement_deliveries')->value('status'));
        $this->artisan('notifications:send-pending')
            ->expectsOutputToContain('1 notifikasi diproses.')
            ->assertExitCode(0);
        $this->assertSame('sent', DB::table('announcement_deliveries')->value('status'));

        // Resending never duplicates deliveries (updateOrInsert), it only re-queues the same row.
        $this->withSession(['sensitive_auth_at' => time()])->actingAs($admin)->post("/platform/announcements/{$announcement->id}/send")->assertRedirect();
        $this->assertSame(1, DB::table('announcement_deliveries')->where('announcement_id', $announcement->id)->count());
        $this->artisan('notifications:send-pending')->expectsOutputToContain('1 notifikasi diproses.')->assertExitCode(0);
        // Idle drain is a no-op: nothing queued remains.
        $this->artisan('notifications:send-pending')->expectsOutputToContain('0 notifikasi diproses.')->assertExitCode(0);
    }
}
