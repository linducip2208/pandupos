<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BarcodeProfile;
use App\Models\BundleItem;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\SerialNumber;
use App\Models\StockReservation;
use App\Models\WarehouseLocation;
use App\Services\BarcodeParserService;
use App\Services\BatchInventoryService;
use App\Services\PriceResolverService;
use App\Services\SerialNumberService;
use App\Services\StockReservationService;
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

    public function storeBatch(Request $request, BatchInventoryService $batches)
    {
        $this->authorize('create', Product::class);
        $tenantId = TenantContext::idOrFail();
        $data = $request->validate([
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'product_variant_id' => ['required', Rule::exists('product_variants', 'id')->where('tenant_id', $tenantId)],
            'batch_number' => 'required|string|max:128',
            'quantity' => 'required|numeric|gt:0',
            'unit_cost' => 'required|numeric|min:0',
            'manufactured_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after_or_equal:manufactured_at',
            'supplier_id' => ['nullable', Rule::exists('contacts', 'id')->where('tenant_id', $tenantId)],
            'purchase_id' => ['nullable', Rule::exists('purchases', 'id')->where('tenant_id', $tenantId)],
        ]);

        $batch = $batches->receive(
            $tenantId,
            (int) $data['warehouse_id'],
            (int) $data['product_variant_id'],
            $data['batch_number'],
            (float) $data['quantity'],
            (float) $data['unit_cost'],
            $data['manufactured_at'] ?? null,
            $data['expires_at'] ?? null,
            $data['supplier_id'] ?? null,
            $data['purchase_id'] ?? null,
        );

        return response()->json(['data' => $batch], 201);
    }

    public function expiry(Request $request, BatchInventoryService $batches)
    {
        $this->authorize('viewAny', Product::class);
        $data = $request->validate(['days' => ['nullable', Rule::in([7, 30, 60, 90])]]);

        return response()->json([
            'data' => $batches->expirySummary(TenantContext::idOrFail(), (int) ($data['days'] ?? 30)),
        ]);
    }

    public function storeSerial(Request $request, SerialNumberService $serials)
    {
        $this->authorize('create', Product::class);
        $tenantId = TenantContext::idOrFail();
        $data = $request->validate([
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'product_variant_id' => ['required', Rule::exists('product_variants', 'id')->where('tenant_id', $tenantId)],
            'serial_number' => ['required', 'string', 'max:255', Rule::unique('serial_numbers')->where('tenant_id', $tenantId)],
            'unit_cost' => 'required|numeric|min:0',
            'inventory_batch_id' => ['nullable', Rule::exists('inventory_batches', 'id')->where('tenant_id', $tenantId)],
            'purchase_id' => ['nullable', Rule::exists('purchases', 'id')->where('tenant_id', $tenantId)],
        ]);
        $serial = $serials->receive(
            $tenantId,
            (int) $data['warehouse_id'],
            (int) $data['product_variant_id'],
            $data['serial_number'],
            (float) $data['unit_cost'],
            $data['inventory_batch_id'] ?? null,
            $data['purchase_id'] ?? null,
        );

        return response()->json(['data' => $serial], 201);
    }

    public function serials()
    {
        $this->authorize('viewAny', Product::class);

        return response()->json(['data' => SerialNumber::with(['variant', 'warehouse'])->latest()->paginate(20)]);
    }

    public function locations(Request $request)
    {
        $this->authorize('viewAny', Product::class);
        $tenantId = TenantContext::idOrFail();
        $data = $request->validate([
            'warehouse_id' => ['nullable', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
        ]);

        return response()->json(['data' => WarehouseLocation::query()
            ->when($data['warehouse_id'] ?? null, fn ($query, $warehouseId) => $query->where('warehouse_id', $warehouseId))
            ->orderBy('code')->paginate(50)]);
    }

    public function storeLocation(Request $request)
    {
        $this->authorize('create', Product::class);
        $tenantId = TenantContext::idOrFail();
        $data = $request->validate([
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'code' => ['required', 'string', 'max:64', Rule::unique('warehouse_locations')->where('tenant_id', $tenantId)->where('warehouse_id', $request->integer('warehouse_id'))],
            'zone' => 'nullable|string|max:64',
            'rack' => 'nullable|string|max:64',
            'shelf' => 'nullable|string|max:64',
            'bin' => 'nullable|string|max:64',
            'is_active' => 'sometimes|boolean',
        ]);

        return response()->json(['data' => WarehouseLocation::create($data + ['tenant_id' => $tenantId])], 201);
    }

    public function reservations()
    {
        $this->authorize('viewAny', Product::class);

        return response()->json(['data' => StockReservation::with(['variant', 'warehouse', 'warehouseLocation'])
            ->latest()->paginate(50)]);
    }

    public function storeReservation(Request $request, StockReservationService $reservations)
    {
        $this->authorize('create', Product::class);
        $tenantId = TenantContext::idOrFail();
        $data = $request->validate([
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'warehouse_location_id' => ['nullable', Rule::exists('warehouse_locations', 'id')->where('tenant_id', $tenantId)],
            'product_variant_id' => ['required', Rule::exists('product_variants', 'id')->where('tenant_id', $tenantId)],
            'inventory_batch_id' => ['nullable', Rule::exists('inventory_batches', 'id')->where('tenant_id', $tenantId)],
            'quantity' => 'required|numeric|gt:0',
            'source_type' => 'required|in:sales_order,held_sale,ecommerce_order',
            'source_id' => 'nullable|integer|min:1',
            'idempotency_key' => 'nullable|string|max:100',
            'expires_at' => 'nullable|date|after:now',
        ]);

        return response()->json(['data' => $reservations->reserve($data + ['tenant_id' => $tenantId], $request->user()?->id)], 201);
    }

    public function releaseReservation(Request $request, StockReservation $reservation, StockReservationService $reservations)
    {
        $this->authorize('create', Product::class);

        return response()->json(['data' => $reservations->release($reservation, $request->user()?->id)]);
    }

    public function consumeReservation(Request $request, StockReservation $reservation, StockReservationService $reservations)
    {
        $this->authorize('create', Product::class);
        $data = $request->validate([
            'reference_type' => 'required|in:sale,delivery,ecommerce_order',
            'reference_id' => 'required|integer|min:1',
        ]);

        return response()->json(['data' => $reservations->consume(
            $reservation, $data['reference_type'], (int) $data['reference_id'], $request->user()?->id
        )]);
    }
}
