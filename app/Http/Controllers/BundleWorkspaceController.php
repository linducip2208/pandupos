<?php

namespace App\Http\Controllers;

use App\Models\BundleItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\AuditService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BundleWorkspaceController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Product::class);

        return view('bundles.index', [
            'bundles' => Product::query()->where('product_type', 'bundle')->with('bundleItems.componentVariant.product')->orderBy('name')->get(),
            'variants' => ProductVariant::query()->with('product')->where('is_active', true)->orderBy('sku')->get(),
        ]);
    }

    public function sync(Request $request, Product $product, AuditService $audit): RedirectResponse
    {
        $this->authorize('create', Product::class);
        abort_unless($product->tenant_id === TenantContext::idOrFail() && $product->product_type === 'bundle', 404);
        $tenantId = $product->tenant_id;
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.component_variant_id' => ['required', 'distinct', Rule::exists('product_variants', 'id')->where('tenant_id', $tenantId)],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);
        $before = $product->load('bundleItems')->bundleItems->toArray();
        DB::transaction(function () use ($product, $tenantId, $data) {
            BundleItem::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('bundle_product_id', $product->id)->delete();
            foreach ($data['items'] as $item) {
                abort_if(ProductVariant::withoutGlobalScopes()->findOrFail($item['component_variant_id'])->product_id === $product->id, 422, 'Bundle cannot contain its own variant.');
                BundleItem::withoutGlobalScopes()->create($item + ['tenant_id' => $tenantId, 'bundle_product_id' => $product->id]);
            }
        });
        $audit->log($tenantId, $request->user()->id, 'bundle.components.synced', Product::class, $product->id, $before, $product->fresh('bundleItems')->bundleItems->toArray());

        return back()->with('status', 'Komponen bundle diperbarui. Stok hanya dipotong dari komponen saat bundle dijual.');
    }
}
