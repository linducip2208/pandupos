<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use Illuminate\Http\Request;

class SubscriptionAdminController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizePlatform($request, 'platform.subscriptions.manage');
        $q = Subscription::withoutGlobalScopes()->with(['plan', 'tenant'])->orderByDesc('id');
        if ($s = $request->get('status')) {
            $q->where('status', $s);
        }
        $subs = $q->paginate(20)->withQueryString();

        return view('platform.subscriptions.index', compact('subs'));
    }

    protected function authorizePlatform(Request $request, string $permission): void
    {
        if ($request->user()->is_platform_admin) {
            return;
        }
        abort_unless($request->user()->can($permission), 403);
    }
}
