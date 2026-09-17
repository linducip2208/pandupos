<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\UnitConversion;
use App\Models\Warehouse;
use App\Services\AuditService;
use App\Services\UnitConversionService;
use App\Services\UsageLimitService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductMasterController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Product::class);
        $products = Product::with(['category', 'brand', 'unit', 'variants', 'locations.warehouse'])
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where(fn ($nested) => $nested->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")->orWhere('barcode', 'like', "%{$search}%")))
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->string('status')->toString() === 'active'))
            ->latest()->paginate(20)->withQueryString();

        return view('product-master.index', $this->options() + compact('products'));
    }

    public function create(): View
    {
        $this->authorize('create', Product::class);

        return view('product-master.form', $this->options() + ['product' => new Product]);
    }

    public function store(Request $request, UsageLimitService $usage, AuditService $audit): RedirectResponse
    {
        $this->authorize('create', Product::class);
        $tenantId = TenantContext::idOrFail();
        $usage->assertCanCreate($tenantId, 'products.max');
        $data = $this->validated($request, $tenantId);
        $product = DB::transaction(function () use ($request, $data, $tenantId, $audit) {
            $payload = $this->productPayload($request, $data);
            $product = Product::create($payload);
            $this->syncVariants($product, $data['variants']);
            $this->syncLocations($product, $data['warehouse_ids'] ?? []);
            $audit->log($tenantId, $request->user()->id, 'product.created', Product::class, $product->id, null, $product->load(['variants', 'locations'])->toArray());

            return $product;
        });

        return redirect()->route('product-master.show', $product)->with('status', 'Produk berhasil dibuat.');
    }

    public function show(Product $product): View
    {
        $this->authorize('view', $product);

        return view('product-master.show', ['product' => $product->load(['category.parent', 'brand', 'unit', 'variants', 'locations.warehouse'])]);
    }

    public function edit(Product $product): View
    {
        $this->authorize('update', $product);

        return view('product-master.form', $this->options() + ['product' => $product->load(['variants', 'locations'])]);
    }

    public function update(Request $request, Product $product, AuditService $audit): RedirectResponse
    {
        $this->authorize('update', $product);
        $data = $this->validated($request, $product->tenant_id, $product);
        DB::transaction(function () use ($request, $product, $data, $audit) {
            $before = $product->load(['variants', 'locations'])->toArray();
            $product->update($this->productPayload($request, $data, $product));
            $this->syncVariants($product, $data['variants']);
            $this->syncLocations($product, $data['warehouse_ids'] ?? []);
            $audit->log($product->tenant_id, $request->user()->id, 'product.updated', Product::class, $product->id, $before, $product->fresh()->load(['variants', 'locations'])->toArray());
        });

        return redirect()->route('product-master.show', $product)->with('status', 'Produk berhasil diperbarui.');
    }

    public function archive(Request $request, Product $product, AuditService $audit): RedirectResponse
    {
        $this->authorize('update', $product);
        if (! $product->is_active) {
            return back()->with('status', 'Produk sudah nonaktif.');
        }
        $before = $product->toArray();
        $product->update(['is_active' => false]);
        $audit->log($product->tenant_id, $request->user()->id, 'product.archived', Product::class, $product->id, $before, $product->fresh()->toArray());

        return redirect()->route('product-master.index')->with('status', 'Produk dinonaktifkan tanpa menghapus histori transaksi.');
    }

    public function storeCategory(Request $request, AuditService $audit): RedirectResponse
    {
        $this->authorize('create', Product::class);
        $tenantId = TenantContext::idOrFail();
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'parent_id' => ['nullable', Rule::exists('categories', 'id')->where('tenant_id', $tenantId)]]);
        $category = Category::create($data);
        $audit->log($tenantId, $request->user()->id, 'category.created', Category::class, $category->id, null, $category->toArray());

        return back()->with('status', 'Kategori berhasil dibuat.');
    }

    public function storeBrand(Request $request, AuditService $audit): RedirectResponse
    {
        $this->authorize('create', Product::class);
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $brand = Brand::create($data);
        $audit->log(TenantContext::idOrFail(), $request->user()->id, 'brand.created', Brand::class, $brand->id, null, $brand->toArray());

        return back()->with('status', 'Merek berhasil dibuat.');
    }

    public function storeUnit(Request $request, AuditService $audit): RedirectResponse
    {
        $this->authorize('create', Product::class);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'short_name' => ['required', 'string', 'max:16']]);
        $unit = Unit::create($data);
        $audit->log(TenantContext::idOrFail(), $request->user()->id, 'unit.created', Unit::class, $unit->id, null, $unit->toArray());

        return back()->with('status', 'Satuan berhasil dibuat.');
    }

    public function storeUnitConversion(Request $request, UnitConversionService $service, AuditService $audit): RedirectResponse
    {
        $this->authorize('create', Product::class);
        $tenantId = TenantContext::idOrFail();
        $data = $request->validate([
            'from_unit_id' => ['required', Rule::exists('units', 'id')->where('tenant_id', $tenantId)],
            'to_unit_id' => ['required', 'different:from_unit_id', Rule::exists('units', 'id')->where('tenant_id', $tenantId)],
            'factor' => ['required', 'numeric', 'gt:0'],
        ]);
        $conversion = $service->define($tenantId, (int) $data['from_unit_id'], (int) $data['to_unit_id'], $data['factor']);
        $audit->log($tenantId, $request->user()->id, 'catalog.unit_conversion.saved', UnitConversion::class, $conversion->id, null, $conversion->toArray());

        return back()->with('status', 'Konversi satuan tersimpan. Arah inverse dan rantai dihitung otomatis.');
    }

    public function destroyUnitConversion(Request $request, UnitConversion $conversion, AuditService $audit): RedirectResponse
    {
        $this->authorize('create', Product::class);
        abort_unless($conversion->tenant_id === TenantContext::idOrFail(), 404);
        $before = $conversion->toArray();
        $conversion->delete();
        $audit->log(TenantContext::idOrFail(), $request->user()->id, 'catalog.unit_conversion.deleted', UnitConversion::class, $conversion->id, $before, null);

        return back()->with('status', 'Konversi satuan dihapus.');
    }

    public function updateCategory(Request $request, Category $category, AuditService $audit): RedirectResponse
    {
        $this->authorize('create', Product::class);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'parent_id' => ['nullable', Rule::exists('categories', 'id')->where('tenant_id', $category->tenant_id), Rule::notIn([$category->id])]]);
        $before = $category->toArray();
        $category->update($data);
        $audit->log($category->tenant_id, $request->user()->id, 'category.updated', Category::class, $category->id, $before, $category->fresh()->toArray());

        return back()->with('status', 'Kategori diperbarui.');
    }

    public function updateBrand(Request $request, Brand $brand, AuditService $audit): RedirectResponse
    {
        $this->authorize('create', Product::class);
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $before = $brand->toArray();
        $brand->update($data);
        $audit->log($brand->tenant_id, $request->user()->id, 'brand.updated', Brand::class, $brand->id, $before, $brand->fresh()->toArray());

        return back()->with('status', 'Merek diperbarui.');
    }

    public function updateUnit(Request $request, Unit $unit, AuditService $audit): RedirectResponse
    {
        $this->authorize('create', Product::class);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'short_name' => ['required', 'string', 'max:16']]);
        $before = $unit->toArray();
        $unit->update($data);
        $audit->log($unit->tenant_id, $request->user()->id, 'unit.updated', Unit::class, $unit->id, $before, $unit->fresh()->toArray());

        return back()->with('status', 'Satuan diperbarui.');
    }

    public function archiveMaster(Request $request, string $type, int $id, AuditService $audit): RedirectResponse
    {
        $this->authorize('create', Product::class);
        $models = ['category' => Category::class, 'brand' => Brand::class, 'unit' => Unit::class];
        abort_unless(isset($models[$type]), 404);
        $record = $models[$type]::findOrFail($id);
        $before = $record->toArray();
        $record->update(['is_active' => false]);
        $audit->log($record->tenant_id, $request->user()->id, $type.'.archived', $models[$type], $record->id, $before, $record->fresh()->toArray());

        return back()->with('status', 'Master dinonaktifkan tanpa menghapus referensi histori.');
    }

    private function validated(Request $request, int $tenantId, ?Product $product = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'], 'product_type' => ['required', Rule::in(['stock', 'service', 'bundle'])],
            'sku' => ['nullable', 'string', 'max:64', Rule::unique('products')->where('tenant_id', $tenantId)->ignore($product)],
            'barcode' => ['nullable', 'string', 'max:64'], 'category_id' => ['nullable', Rule::exists('categories', 'id')->where('tenant_id', $tenantId)],
            'brand_id' => ['nullable', Rule::exists('brands', 'id')->where('tenant_id', $tenantId)], 'unit_id' => ['nullable', Rule::exists('units', 'id')->where('tenant_id', $tenantId)],
            'alert_quantity' => ['required', 'numeric', 'min:0'], 'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'tax_method' => ['required', Rule::in(['inclusive', 'exclusive', 'zero', 'exempt'])], 'track_inventory' => ['nullable', 'boolean'], 'is_active' => ['nullable', 'boolean'],
            'image' => ['nullable', 'image', 'max:2048'], 'warehouse_ids' => ['nullable', 'array'], 'warehouse_ids.*' => [Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'variants' => ['required', 'array', 'min:1'], 'variants.*.id' => ['nullable', 'integer'], 'variants.*.name' => ['required', 'string', 'max:255'],
            'variants.*.sku' => ['nullable', 'string', 'max:64'], 'variants.*.barcode' => ['nullable', 'string', 'max:64'],
            'variants.*.purchase_price' => ['required', 'numeric', 'min:0'], 'variants.*.sell_price' => ['required', 'numeric', 'min:0'],
            'variants.*.attributes_text' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function productPayload(Request $request, array $data, ?Product $product = null): array
    {
        $payload = collect($data)->only(['name', 'product_type', 'sku', 'barcode', 'category_id', 'brand_id', 'unit_id', 'alert_quantity', 'tax_rate', 'tax_method'])->all();
        $payload['track_inventory'] = $data['product_type'] === 'service' ? false : $request->boolean('track_inventory');
        $payload['is_active'] = $request->boolean('is_active', true);
        if ($request->hasFile('image')) {
            $payload['image_path'] = $request->file('image')->store('products', 'public');
        } elseif ($product) {
            $payload['image_path'] = $product->image_path;
        }

        return $payload;
    }

    private function syncVariants(Product $product, array $rows): void
    {
        $kept = [];
        foreach ($rows as $row) {
            $attributes = collect(preg_split('/\r\n|\r|\n/', (string) ($row['attributes_text'] ?? '')))->filter()->mapWithKeys(function ($entry) {
                [$key, $value] = array_pad(explode(':', $entry, 2), 2, '');

                return [trim($key) => trim($value)];
            })->filter(fn ($value, $key) => $key !== '')->all();
            $variant = $product->variants()->updateOrCreate(['id' => $row['id'] ?? null], [
                'tenant_id' => $product->tenant_id, 'name' => $row['name'], 'sku' => $row['sku'] ?: null, 'barcode' => $row['barcode'] ?: null,
                'purchase_price' => $row['purchase_price'], 'sell_price' => $row['sell_price'], 'attributes' => $attributes ?: null, 'is_active' => true,
            ]);
            $kept[] = $variant->id;
        }
        $product->variants()->whereNotIn('id', $kept)->update(['is_active' => false]);
    }

    private function syncLocations(Product $product, array $warehouseIds): void
    {
        $product->locations()->update(['is_active' => false]);
        foreach (array_unique($warehouseIds) as $warehouseId) {
            $product->locations()->updateOrCreate(['warehouse_id' => $warehouseId], ['tenant_id' => $product->tenant_id, 'is_active' => true]);
        }
    }

    private function options(): array
    {
        return [
            'categories' => Category::with('parent')->orderBy('name')->get(),
            'brands' => Brand::orderBy('name')->get(),
            'units' => Unit::orderBy('name')->get(),
            'unitConversions' => UnitConversion::with(['fromUnit', 'toUnit'])->latest()->get(),
            'warehouses' => Warehouse::orderBy('name')->get(),
        ];
    }
}
