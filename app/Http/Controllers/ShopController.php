<?php

namespace App\Http\Controllers;

use App\Models\EcommerceOrder;
use App\Models\Tenant;
use App\Services\EcommerceService;
use App\Services\ModuleRegistry;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class ShopController extends Controller
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

        return view('shop.catalog', ['tenant' => $tenant, 'items' => $shop->catalog($tenant->id)]);
    }

    public function addToCart(Request $request, EcommerceService $shop, string $slug)
    {
        $tenant = $this->shopTenant($slug);
        $data = $request->validate([
            'email' => 'required|email|max:128', 'variant_id' => 'required|integer',
            'quantity' => 'required|numeric|gt:0',
        ]);
        $cart = $shop->addToCart($tenant->id, $data['email'], (int) $data['variant_id'], (float) $data['quantity']);

        return back()->with('status', 'Keranjang diperbarui.')->with('cart_email', $data['email'])->with('cart', $cart);
    }

    public function checkoutForm(EcommerceService $shop, string $slug, Request $request)
    {
        $tenant = $this->shopTenant($slug);
        $email = $request->input('email', $request->session()->get('cart_email', ''));

        return view('shop.checkout', ['tenant' => $tenant, 'email' => $email, 'cart' => $email ? $shop->cartContents($tenant->id, $email) : ['lines' => [], 'total' => 0]]);
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
        $order = $shop->checkout($tenant->id, $data);

        return redirect()->route('shop.track', [$tenant->slug, 'number' => $order->number, 'email' => $order->email])
            ->with('status', 'Pesanan '.$order->number.' dibuat. Lakukan pembayaran.');
    }

    public function track(EcommerceService $shop, string $slug, Request $request)
    {
        $tenant = $this->shopTenant($slug);
        $order = null;
        if ($request->filled(['number', 'email'])) {
            $order = EcommerceOrder::withoutGlobalScopes()->where('tenant_id', $tenant->id)
                ->where('number', $request->input('number'))->where('email', strtolower($request->input('email')))->first();
        }

        return view('shop.track', ['tenant' => $tenant, 'order' => $order]);
    }
}
