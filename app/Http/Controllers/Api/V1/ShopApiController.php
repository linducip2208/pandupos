<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EcommerceOrder;
use App\Models\Tenant;
use App\Services\EcommerceService;
use App\Services\ModuleRegistry;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class ShopApiController extends Controller
{
    private function shopTenant(string $slug): Tenant
    {
        $tenant = Tenant::query()->where('slug', $slug)->firstOrFail();
        abort_unless(in_array($tenant->status, ['trial', 'active'], true), 404);
        abort_unless(app(ModuleRegistry::class)->isEnabled($tenant->id, 'ecommerce'), 404);
        TenantContext::set($tenant);

        return $tenant;
    }

    public function catalog(EcommerceService $shop, string $slug)
    {
        $tenant = $this->shopTenant($slug);

        return response()->json(['data' => $shop->catalog($tenant->id)]);
    }

    public function addToCart(Request $request, EcommerceService $shop, string $slug)
    {
        $tenant = $this->shopTenant($slug);
        $data = $request->validate([
            'email' => 'required|email|max:128', 'variant_id' => 'required|integer',
            'quantity' => 'required|numeric|gt:0',
        ]);

        return response()->json(['data' => $shop->addToCart($tenant->id, $data['email'], (int) $data['variant_id'], (float) $data['quantity'])], 201);
    }

    public function checkout(Request $request, EcommerceService $shop, string $slug)
    {
        $tenant = $this->shopTenant($slug);
        $data = $request->validate([
            'email' => 'required|email|max:128', 'recipient' => 'required|string|max:160',
            'phone' => 'required|string|max:64', 'address' => 'required|string',
            'city' => 'nullable|string|max:128', 'postal_code' => 'nullable|string|max:16',
            'shipping_method' => 'nullable|string',
        ]);

        return response()->json(['data' => $shop->checkout($tenant->id, $data)->load('lines')], 201);
    }

    public function track(Request $request, string $slug)
    {
        $tenant = $this->shopTenant($slug);
        $data = $request->validate(['number' => 'required|string', 'email' => 'required|email']);
        $order = EcommerceOrder::withoutGlobalScopes()->where('tenant_id', $tenant->id)
            ->where('number', $data['number'])->where('email', strtolower($data['email']))->firstOrFail();

        return response()->json(['data' => $order->load('lines')]);
    }

    public function adminOrders()
    {
        $this->authorize('viewAny', EcommerceOrder::class);

        return response()->json(['data' => EcommerceOrder::query()->with('lines')->orderByDesc('id')->limit(100)->get()]);
    }

    public function adminTransition(Request $request, EcommerceService $shop, int $order)
    {
        $model = EcommerceOrder::query()->findOrFail($order);
        $this->authorize('manage', $model);
        $data = $request->validate([
            'action' => 'required|in:pay,ship,deliver,cancel',
            'amount' => 'nullable|numeric|gt:0', 'method' => 'nullable|string|max:32',
            'tracking_number' => 'nullable|string|max:64',
        ]);
        $result = match ($data['action']) {
            'pay' => $shop->markPaid($model, (float) ($data['amount'] ?? $model->total), (string) ($data['method'] ?? 'transfer'), $request->user()->id),
            'ship' => $shop->ship($model, $data['tracking_number'] ?? null, $request->user()->id),
            'deliver' => $shop->deliver($model, $request->user()->id),
            'cancel' => $shop->cancel($model, $request->user()->id),
        };

        return response()->json(['data' => $result]);
    }
}
