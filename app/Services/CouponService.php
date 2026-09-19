<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use Illuminate\Support\Facades\DB;

/** Server-side atomic coupon validation. */
final class CouponService
{
    public function validate(string $code, int $tenantId, int $planId, float $amount): array
    {
        $coupon = Coupon::where('code', $code)->where('is_active', true)->first();

        if (! $coupon) {
            abort(422, 'Invalid coupon.');
        }
        if ($coupon->valid_from && now()->lt($coupon->valid_from)) {
            abort(422, 'Coupon not yet valid.');
        }
        if ($coupon->valid_until && now()->gt($coupon->valid_until)) {
            abort(422, 'Coupon expired.');
        }
        if ($coupon->applicable_plan_ids && ! in_array($planId, $coupon->applicable_plan_ids, true)) {
            abort(422, 'Coupon not applicable to this plan.');
        }
        if ($coupon->max_redemptions && $coupon->redemptions()->withoutGlobalScopes()->count() >= $coupon->max_redemptions) {
            abort(422, 'Coupon redemption limit reached.');
        }
        if ($coupon->redemptions()->where('tenant_id', $tenantId)->count() >= $coupon->per_tenant_limit) {
            abort(422, 'Coupon already used.');
        }

        $discount = $coupon->discount_type === 'percentage'
            ? round($amount * ((float) $coupon->discount_value / 100), 2)
            : min($amount, (float) $coupon->discount_value);

        return [$coupon, $discount];
    }

    public function redeem(Coupon $coupon, int $tenantId, ?int $subscriptionId, float $discount): CouponRedemption
    {
        return DB::transaction(function () use ($coupon, $tenantId, $subscriptionId, $discount) {
            // Serialize concurrent redemptions on the coupon row so the caps below
            // hold under race: loser re-checks counts and fails instead of over-redeeming.
            $locked = Coupon::withoutGlobalScopes()->lockForUpdate()->findOrFail($coupon->id);
            if ($locked->max_redemptions && $locked->redemptions()->withoutGlobalScopes()->count() >= $locked->max_redemptions) {
                abort(422, 'Coupon redemption limit reached.');
            }
            if ($locked->redemptions()->where('tenant_id', $tenantId)->count() >= $locked->per_tenant_limit) {
                abort(422, 'Coupon already used.');
            }

            return CouponRedemption::create([
                'coupon_id' => $locked->id, 'tenant_id' => $tenantId,
                'subscription_id' => $subscriptionId, 'discount_amount' => $discount,
            ]);
        });
    }

    /** Atomic validate+redeem in one locked transaction (no TOCTOU between check and insert). */
    public function redeemCode(string $code, int $tenantId, int $planId, float $amount, ?int $subscriptionId = null): array
    {
        return DB::transaction(function () use ($code, $tenantId, $planId, $amount, $subscriptionId) {
            $coupon = Coupon::withoutGlobalScopes()->where('code', $code)->where('is_active', true)->lockForUpdate()->first();
            if (! $coupon) {
                abort(422, 'Invalid coupon.');
            }
            if ($coupon->valid_from && now()->lt($coupon->valid_from)) {
                abort(422, 'Coupon not yet valid.');
            }
            if ($coupon->valid_until && now()->gt($coupon->valid_until)) {
                abort(422, 'Coupon expired.');
            }
            if ($coupon->applicable_plan_ids && ! in_array($planId, $coupon->applicable_plan_ids, true)) {
                abort(422, 'Coupon not applicable to this plan.');
            }
            if ($coupon->max_redemptions && $coupon->redemptions()->withoutGlobalScopes()->count() >= $coupon->max_redemptions) {
                abort(422, 'Coupon redemption limit reached.');
            }
            if ($coupon->redemptions()->where('tenant_id', $tenantId)->count() >= $coupon->per_tenant_limit) {
                abort(422, 'Coupon already used.');
            }
            $discount = $coupon->discount_type === 'percentage'
                ? round($amount * ((float) $coupon->discount_value / 100), 2)
                : min($amount, (float) $coupon->discount_value);
            $redemption = CouponRedemption::create([
                'coupon_id' => $coupon->id, 'tenant_id' => $tenantId,
                'subscription_id' => $subscriptionId, 'discount_amount' => $discount,
            ]);

            return [$coupon, $discount, $redemption];
        });
    }
}
