<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizePlatform($request, 'platform.coupons.manage');
        $coupons = Coupon::withCount('redemptions')->orderByDesc('id')->paginate(20);

        return view('platform.coupons.index', compact('coupons'));
    }

    public function store(Request $request)
    {
        $this->authorizePlatform($request, 'platform.coupons.manage');
        $data = $request->validate([
            'code' => 'required|string|max:32|unique:coupons,code',
            'discount_type' => 'required|in:fixed,percentage',
            'discount_value' => 'required|numeric|min:0',
            'max_redemptions' => 'nullable|integer|min:1',
            'per_tenant_limit' => 'nullable|integer|min:1',
            'valid_from' => 'nullable|date', 'valid_until' => 'nullable|date|after_or_equal:valid_from',
            'is_active' => 'boolean',
        ]);
        Coupon::create($data + ['per_tenant_limit' => $data['per_tenant_limit'] ?? 1, 'is_active' => $data['is_active'] ?? true]);

        return back()->with('status', 'Coupon created.');
    }

    protected function authorizePlatform(Request $request, string $permission): void
    {
        if ($request->user()->is_platform_admin) {
            return;
        }
        abort_unless($request->user()->can($permission), 403);
    }
}
