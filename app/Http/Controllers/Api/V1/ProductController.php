<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\UsageLimitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Product::class);
        $q = Product::with('variants')->orderBy('name');

        if ($s = $request->get('search')) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%"));
        }

        return response()->json($q->paginate($request->get('per_page', 15)));
    }

    public function store(Request $request, UsageLimitService $usage)
    {
        $this->authorize('create', Product::class);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|max:64',
            'barcode' => 'nullable|string|max:64',
            'category_id' => 'nullable|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'unit_id' => 'nullable|exists:units,id',
            'sell_price' => 'required|numeric|min:0',
            'purchase_price' => 'nullable|numeric|min:0',
        ]);

        $usage->assertCanCreate(\App\Support\TenantContext::id(), 'products.max');

        return DB::transaction(function () use ($data) {
            $product = Product::create($data);
            $product->variants()->create([
                'tenant_id' => $product->tenant_id,
                'name' => 'Default',
                'sku' => $data['sku'] ?? null,
                'sell_price' => $data['sell_price'],
                'purchase_price' => $data['purchase_price'] ?? 0,
            ]);

            return response()->json($product->load('variants'), 201);
        });
    }

    public function show(Product $product)
    {
        $this->authorize('view', $product);

        return response()->json($product->load('variants'));
    }
}
