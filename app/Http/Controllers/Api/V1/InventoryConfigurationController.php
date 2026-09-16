<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BarcodeProfile;
use App\Models\BundleItem;
use App\Models\PriceList;
use App\Models\Product;
use App\Services\BarcodeParserService;
use App\Services\PriceResolverService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InventoryConfigurationController extends Controller
{
    public function storeBarcodeProfile(Request $request)
    {
        $this->authorize('create', Product::class);
        $tenantId = TenantContext::idOrFail();
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'prefix' => ['required', 'string', 'max:16', Rule::unique('barcode_profiles')->where('tenant_id', $tenantId)],
            'total_length' => 'required|integer|min:6|max:32',
            'item_start' => 'required|integer|min:0|max:31',
            'item_length' => 'required|integer|min:1|max:16',
            'value_start' => 'required|integer|min:0|max:31',
            'value_length' => 'required|integer|min:1|max:16',
            'value_type' => ['required', Rule::in(['weight', 'price'])],
            'decimal_places' => 'required|integer|min:0|max:6',
            'is_active' => 'nullable|boolean',
        ]);
        abort_if(
            $data['total_length'] < $data['item_start'] + $data['item_length']
            || $data['total_length'] < $data['value_start'] + $data['value_length'],
            422,
            'Barcode field positions exceed total length.'
        );

        return response()->json(['data' => BarcodeProfile::create($data)], 201);
    }

    public function parseBarcode(Request $request, BarcodeParserService $parser)
    {
        $this->authorize('viewAny', Product::class);
        $data = $request->validate(['barcode' => 'required|string|max:64']);

        return response()->json(['data' => $parser->parse(TenantContext::idOrFail(), $data['barcode'])]);
    }

    public function priceLists()
    {
        $this->authorize('viewAny', Product::class);

        return response()->json(['data' => PriceList::with('items')->orderByDesc('priority')->paginate(20)]);
    }

    public function storePriceList(Request $request, PriceResolverService $prices)
    {
        $this->authorize('create', Product::class);
        $tenantId = TenantContext::idOrFail();
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'scope' => ['required', Rule::in(['retail', 'wholesale', 'member', 'vip', 'branch', 'customer_group', 'promotion'])],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'customer_group_id' => ['nullable', Rule::exists('customer_groups', 'id')->where('tenant_id', $tenantId)],
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'priority' => 'nullable|integer|min:0|max:100000',
            'is_active' => 'nullable|boolean',
            'items' => 'required|array|min:1',
            'items.*.product_variant_id' => ['required', Rule::exists('product_variants', 'id')->where('tenant_id', $tenantId)],
            'items.*.price' => 'required|numeric|min:0',
            'items.*.minimum_quantity' => 'nullable|numeric|min:0',
        ]);

        $priceList = DB::transaction(function () use ($data) {
            $items = $data['items'];
            unset($data['items']);
            $priceList = PriceList::create($data);
            foreach ($items as $item) {
                $priceList->items()->create($item);
            }

            return $priceList->load('items');
        });
        $prices->auditChange($tenantId, $priceList->id, null, $priceList->toArray(), $request->user()?->id);

        return response()->json(['data' => $priceList], 201);
    }

    public function setBundleItems(Request $request, Product $product)
    {
        $this->authorize('create', Product::class);
        abort_unless($product->product_type === 'bundle', 422, 'Product type must be bundle.');
        $tenantId = TenantContext::idOrFail();
        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.component_variant_id' => ['required', 'distinct', Rule::exists('product_variants', 'id')->where('tenant_id', $tenantId)],
            'items.*.quantity' => 'required|numeric|gt:0',
        ]);

        DB::transaction(function () use ($product, $data, $tenantId) {
            BundleItem::withoutGlobalScopes()->where('tenant_id', $tenantId)
                ->where('bundle_product_id', $product->id)->delete();
            foreach ($data['items'] as $item) {
                BundleItem::withoutGlobalScopes()->create($item + [
                    'tenant_id' => $tenantId,
                    'bundle_product_id' => $product->id,
                ]);
            }
        });

        return response()->json(['data' => $product->load('bundleItems.componentVariant')]);
    }
}
