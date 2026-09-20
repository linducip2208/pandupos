<?php

namespace App\Http\Controllers;

use App\Models\EcommerceOrder;
use App\Models\Product;
use App\Services\EcommerceService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class EcommerceWorkspaceController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', EcommerceOrder::class);

        return view('ecommerce.index', [
            'orders' => EcommerceOrder::query()->with('lines.variant')->orderByDesc('id')->limit(100)->get(),
            'products' => Product::query()->where('product_type', 'stock')->orderBy('name')->limit(200)->get(),
        ]);
    }

    public function publish(Request $request, EcommerceService $shop, int $product)
    {
        $model = Product::query()->findOrFail($product);
        $this->authorize('create', EcommerceOrder::class);
        $data = $request->validate(['online' => 'required|boolean']);
        $shop->publishProduct(TenantContext::idOrFail(), $model->id, (bool) $data['online'], $request->user()->id);

        return back()->with('status', 'Katalog diperbarui.');
    }

    public function transition(Request $request, EcommerceService $shop, int $order)
    {
        $model = EcommerceOrder::query()->findOrFail($order);
        $this->authorize('manage', $model);
        $data = $request->validate([
            'action' => 'required|in:pay,ship,deliver,cancel',
            'amount' => 'nullable|numeric|gt:0', 'method' => 'nullable|string|max:32',
            'tracking_number' => 'nullable|string|max:64',
        ]);
        match ($data['action']) {
            'pay' => $shop->markPaid($model, (float) ($data['amount'] ?? $model->total), (string) ($data['method'] ?? 'transfer'), $request->user()->id),
            'ship' => $shop->ship($model, $data['tracking_number'] ?? null, $request->user()->id),
            'deliver' => $shop->deliver($model, $request->user()->id),
            'cancel' => $shop->cancel($model, $request->user()->id),
        };

        return back()->with('status', 'Order diperbarui.');
    }
}
