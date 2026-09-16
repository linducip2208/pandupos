<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AffiliateController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizePlatform($request, 'platform.affiliates.manage');
        $affiliates = Affiliate::orderByDesc('id')->paginate(15);
        $commissions = DB::table('affiliate_commissions')->orderByDesc('id')->limit(20)->get();
        $payouts = DB::table('affiliate_payouts')->orderByDesc('id')->limit(20)->get();
        $pendingTotal = (float) DB::table('affiliate_commissions')->where('status', 'pending')->sum('commission_amount');

        return view('platform.affiliates.index', compact('affiliates', 'commissions', 'payouts', 'pendingTotal'));
    }

    public function payout(Request $request)
    {
        $this->authorizePlatform($request, 'platform.affiliates.manage');
        $data = $request->validate(['affiliate_user_id' => 'required|exists:users,id']);

        return DB::transaction(function () use ($data, $request) {
            $pending = DB::table('affiliate_commissions')
                ->where('affiliate_user_id', $data['affiliate_user_id'])->where('status', 'pending')
                ->lockForUpdate()->get();
            abort_if($pending->isEmpty(), 422, 'No pending commissions.');

            $total = (float) $pending->sum('commission_amount');
            $payoutId = DB::table('affiliate_payouts')->insertGetId([
                'affiliate_user_id' => $data['affiliate_user_id'], 'amount' => $total,
                'status' => 'paid', 'paid_at' => now(), 'created_by' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('affiliate_commissions')->whereIn('id', $pending->pluck('id'))->update(['status' => 'paid']);

            return back()->with('status', "Payout #{$payoutId} paid: {$total}.");
        });
    }

    /**
     * Record commission once per (affiliate, subscription). Prevents duplicates/replay.
     */
    public static function recordCommission(int $affiliateUserId, int $tenantId, int $subscriptionId, float $saleAmount, float $percent): void
    {
        DB::transaction(function () use ($affiliateUserId, $tenantId, $subscriptionId, $saleAmount, $percent) {
            $exists = DB::table('affiliate_commissions')->where('subscription_id', $subscriptionId)->exists();
            if ($exists) {
                return;
            }
            DB::table('affiliate_commissions')->insert([
                'affiliate_user_id' => $affiliateUserId, 'referred_tenant_id' => $tenantId,
                'subscription_id' => $subscriptionId, 'sale_amount' => $saleAmount,
                'commission_percent' => $percent, 'commission_amount' => round($saleAmount * $percent / 100, 2),
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    protected function authorizePlatform(Request $request, string $permission): void
    {
        if ($request->user()->is_platform_admin) {
            return;
        }
        abort_unless($request->user()->can($permission), 403);
    }
}
