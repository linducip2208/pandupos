<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\AuditService;
use App\Services\BatchInventoryService;
use App\Services\StockService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BatchWorkspaceController extends Controller
{
    public function index(Request $request, StockService $stock): View
    {
        $this->authorize('viewAny', Product::class);
        $tenantId = TenantContext::idOrFail();
        $days = (int) $request->integer('days', 30);
        abort_unless(in_array($days, [7, 30, 60, 90], true), 422);

        $batches = InventoryBatch::query()
            ->with(['variant.product', 'warehouse', 'supplier', 'purchase'])
            ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expires_at')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(function (InventoryBatch $batch) use ($stock, $tenantId) {
                $batch->on_hand = $stock->onHandByBatch($tenantId, $batch->warehouse_id, $batch->product_variant_id, $batch->id);

                return $batch;
            })
            ->filter(fn (InventoryBatch $batch) => $batch->on_hand > 0)
            ->values();

        return view('batches.index', [
            'batches' => $batches,
            'days' => $days,
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(),
            'locations' => WarehouseLocation::query()->where('is_active', true)->orderBy('code')->get(),
            'variants' => ProductVariant::query()->with('product')->where('is_active', true)->orderBy('sku')->get(),
            'suppliers' => Contact::query()->whereIn('type', ['supplier', 'both'])->orderBy('name')->get(),
            'purchases' => Purchase::query()->latest()->limit(100)->get(),
        ]);
    }

    public function receive(Request $request, BatchInventoryService $batches, AuditService $audit): RedirectResponse
    {
        $this->authorize('create', Product::class);
        $tenantId = TenantContext::idOrFail();
        $data = $request->validate([
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'product_variant_id' => ['required', Rule::exists('product_variants', 'id')->where('tenant_id', $tenantId)],
            'batch_number' => ['required', 'string', 'max:128'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'manufactured_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:manufactured_at'],
            'supplier_id' => ['nullable', Rule::exists('contacts', 'id')->where('tenant_id', $tenantId)],
            'purchase_id' => ['nullable', Rule::exists('purchases', 'id')->where('tenant_id', $tenantId)],
            'warehouse_location_id' => ['nullable', Rule::exists('warehouse_locations', 'id')->where('tenant_id', $tenantId)->where('is_active', true)],
        ]);

        $batch = $batches->receive(
            $tenantId, (int) $data['warehouse_id'], (int) $data['product_variant_id'], $data['batch_number'],
            (float) $data['quantity'], (float) $data['unit_cost'], $data['manufactured_at'] ?? null,
            $data['expires_at'] ?? null, $data['supplier_id'] ?? null, $data['purchase_id'] ?? null, null,
            $data['warehouse_location_id'] ?? null,
        );
        $audit->log($tenantId, $request->user()->id, 'inventory.batch.received', InventoryBatch::class, $batch->id, null, [
            'batch' => $batch->fresh()->toArray(), 'quantity' => $data['quantity'], 'unit_cost' => $data['unit_cost'],
        ]);

        return back()->with('status', 'Batch diterima dan ledger stok ditambah. Pengeluaran otomatis memakai FEFO serta menolak batch kedaluwarsa.');
    }
}
