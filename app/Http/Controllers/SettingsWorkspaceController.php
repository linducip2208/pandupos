<?php

namespace App\Http\Controllers;

use App\Services\RestaurantService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class SettingsWorkspaceController extends Controller
{
    public function business(RestaurantService $restaurant)
    {
        abort_unless(request()->user()->can('settings.manage'), 403);
        $tenantId = TenantContext::idOrFail();

        return view('settings.business', [
            'restaurant' => $restaurant->isEnabled($tenantId),
        ]);
    }

    public function updateBusiness(Request $request, RestaurantService $restaurant)
    {
        abort_unless($request->user()->can('settings.manage'), 403);
        $data = $request->validate(['restaurant_enabled' => 'nullable|boolean']);
        $restaurant->setEnabled(TenantContext::idOrFail(), $request->boolean('restaurant_enabled'), $request->user()->id);

        return back()->with('status', $request->boolean('restaurant_enabled') ? 'Fitur Restaurant diaktifkan.' : 'Fitur Restaurant dinonaktifkan; POS kembali ke Retail.');
    }
}
