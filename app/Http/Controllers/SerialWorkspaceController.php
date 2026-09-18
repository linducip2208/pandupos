<?php

namespace App\Http\Controllers;

use App\Models\InventoryBatch;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\SerialNumber;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\AuditService;
use App\Services\SerialNumberService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SerialWorkspaceController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Product::class);
        $status = $request->string('status')->toString();
        abort_if($status !== '' && ! in_array($status, $this->statuses(), true), 422);

        return view('serials.index', [
            'serials' => SerialNumber::query()->with(['variant.product', 'warehouse', 'warehouseLocation', 'inventoryBatch', 'purchase', 'salesInvoice', 'movements'])->when($status, fn ($query) => $query->where('status', $status))->latest()->limit(100)->get(),
            'statuses' => $this->statuses(),
            'selectedStatus' => $status,
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(),
            'locations' => WarehouseLocation::query()->where('is_active', true)->with('warehouse')->orderBy('code')->get(),
            'variants' => ProductVariant::query()->with('product')->where('is_active', true)->orderBy('sku')->get(),
            'batches' => InventoryBatch::query()->orderByDesc('id')->limit(100)->get(),
            'purchases' => Purchase::query()->latest()->limit(100)->get(),
        ]);
    }

    public function receive(Request $request, SerialNumberService $serials, AuditService $audit): RedirectResponse
    {
        $this->authorize('create', Product::class);
        $tenantId = TenantContext::idOrFail();
        $data = $request->validate([
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'product_variant_id' => ['required', Rule::exists('product_variants', 'id')->where('tenant_id', $tenantId)],
            'serial_number' => ['required', 'string', 'max:255', Rule::unique('serial_numbers')->where('tenant_id', $tenantId)],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'inventory_batch_id' => ['nullable', Rule::exists('inventory_batches', 'id')->where('tenant_id', $tenantId)],
            'purchase_id' => ['nullable', Rule::exists('purchases', 'id')->where('tenant_id', $tenantId)],
            'warehouse_location_id' => ['nullable', Rule::exists('warehouse_locations', 'id')->where('tenant_id', $tenantId)],
        ]);
        $serial = $serials->receive($tenantId, (int) $data['warehouse_id'], (int) $data['product_variant_id'], $data['serial_number'], (float) $data['unit_cost'], $data['inventory_batch_id'] ?? null, $data['purchase_id'] ?? null, $data['warehouse_location_id'] ?? null);
        $audit->log($tenantId, $request->user()->id, 'inventory.serial.received', SerialNumber::class, $serial->id, null, $serial->fresh()->toArray());

        return back()->with('status', 'Serial diterima, tersedia untuk penjualan, dan ditambahkan ke ledger stok.');
    }

    private function statuses(): array
    {
        return ['received', 'available', 'reserved', 'sold', 'returned', 'damaged', 'transferred'];
    }
}
