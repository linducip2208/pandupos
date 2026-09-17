<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\AuditService;
use App\Services\UsageLimitService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Product::class);
        $q = Product::with(['variants', 'locations'])->orderBy('name');

        if ($s = $request->get('search')) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%"));
        }

        return response()->json($q->paginate($request->get('per_page', 15)));
    }

    public function store(Request $request, UsageLimitService $usage)
    {
        $this->authorize('create', Product::class);
        $tenantId = TenantContext::idOrFail();
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'product_type' => ['required', Rule::in(['stock', 'service', 'bundle'])],
            'sku' => 'nullable|string|max:64',
            'barcode' => 'nullable|string|max:64',
            'image_path' => 'nullable|string|max:2048',
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('tenant_id', $tenantId)],
            'brand_id' => ['nullable', Rule::exists('brands', 'id')->where('tenant_id', $tenantId)],
            'unit_id' => ['nullable', Rule::exists('units', 'id')->where('tenant_id', $tenantId)],
            'alert_quantity' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'tax_method' => ['nullable', Rule::in(['inclusive', 'exclusive', 'zero', 'exempt'])],
            'track_inventory' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'sell_price' => 'required|numeric|min:0',
            'purchase_price' => 'nullable|numeric|min:0',
            'variant_barcode' => 'nullable|string|max:64',
            'variant_attributes' => 'nullable|array',
            'variant_attributes.*' => 'nullable|string|max:255',
            'warehouse_ids' => 'nullable|array',
            'warehouse_ids.*' => [Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
        ]);

        $usage->assertCanCreate(TenantContext::id(), 'products.max');

        return DB::transaction(function () use ($data) {
            $product = Product::create($data);
            $product->variants()->create([
                'tenant_id' => $product->tenant_id,
                'name' => 'Default',
                'sku' => $data['sku'] ?? null,
                'barcode' => $data['variant_barcode'] ?? $data['barcode'] ?? null,
                'attributes' => $data['variant_attributes'] ?? null,
                'sell_price' => $data['sell_price'],
                'purchase_price' => $data['purchase_price'] ?? 0,
            ]);

            foreach (array_unique($data['warehouse_ids'] ?? []) as $warehouseId) {
                $product->locations()->create([
                    'tenant_id' => $product->tenant_id,
                    'warehouse_id' => $warehouseId,
                ]);
            }

            return response()->json($product->load(['variants', 'locations']), 201);
        });
    }

    public function show(Product $product)
    {
        $this->authorize('view', $product);

        return response()->json($product->load(['variants', 'locations']));
    }

    public function update(Request $request, Product $product, AuditService $audit)
    {
        $this->authorize('update', $product);
        $tenantId = TenantContext::idOrFail();
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'], 'product_type' => ['sometimes', Rule::in(['stock', 'service', 'bundle'])],
            'sku' => ['sometimes', 'nullable', 'string', 'max:64', Rule::unique('products')->where('tenant_id', $tenantId)->ignore($product)],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:64'], 'category_id' => ['sometimes', 'nullable', Rule::exists('categories', 'id')->where('tenant_id', $tenantId)],
            'brand_id' => ['sometimes', 'nullable', Rule::exists('brands', 'id')->where('tenant_id', $tenantId)], 'unit_id' => ['sometimes', 'nullable', Rule::exists('units', 'id')->where('tenant_id', $tenantId)],
            'alert_quantity' => ['sometimes', 'numeric', 'min:0'], 'tax_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'tax_method' => ['sometimes', Rule::in(['inclusive', 'exclusive', 'zero', 'exempt'])], 'track_inventory' => ['sometimes', 'boolean'], 'is_active' => ['sometimes', 'boolean'],
        ]);
        $before = $product->toArray();
        $product->update($data);
        $audit->log($tenantId, $request->user()->id, 'product.updated.api', Product::class, $product->id, $before, $product->fresh()->toArray());

        return response()->json($product->fresh()->load(['variants', 'locations']));
    }

    public function archive(Request $request, Product $product, AuditService $audit)
    {
        $this->authorize('update', $product);
        $before = $product->toArray();
        $product->update(['is_active' => false]);
        $audit->log($product->tenant_id, $request->user()->id, 'product.archived.api', Product::class, $product->id, $before, $product->fresh()->toArray());

        return response()->json($product->fresh());
    }
}
