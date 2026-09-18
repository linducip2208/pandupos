<?php

namespace App\Http\Controllers;

use App\Models\InventoryBatch;
use App\Models\ProductVariant;
use App\Models\SerialNumber;
use App\Models\StockAdjustment;
use App\Models\StockCount;
use App\Models\StockReservation;
use App\Models\TransferOrder;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\AuditService;
use App\Services\StockAdjustmentService;
use App\Services\StockCountService;
use App\Services\StockReservationService;
use App\Services\StockTransferService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InventoryWorkspaceController extends Controller
{
    public function index(Request $request): View
    {
        $this->requirePermission($request, 'inventory.view');

        return view('inventory.index', [
            'warehouses' => Warehouse::query()->orderBy('name')->get(),
            'variants' => ProductVariant::query()->with('product')->orderBy('sku')->get(),
            'locations' => WarehouseLocation::query()->with('warehouse')->latest()->limit(30)->get(),
            'batches' => InventoryBatch::query()->latest()->limit(100)->get(),
            'serials' => SerialNumber::query()->where('status', 'available')->orderBy('serial_number')->limit(200)->get(),
            'reservations' => StockReservation::query()->with(['warehouse', 'variant.product'])->latest()->limit(30)->get(),
            'transfers' => TransferOrder::query()->with(['fromWarehouse', 'toWarehouse', 'requester', 'lines.variant.product'])->latest()->limit(30)->get(),
            'adjustments' => StockAdjustment::query()->with(['warehouse', 'lines.variant.product'])->latest()->limit(30)->get(),
            'counts' => StockCount::query()->with(['warehouse', 'lines.variant.product'])->latest()->limit(30)->get(),
        ]);
    }

    public function storeLocation(Request $request, AuditService $audit): RedirectResponse
    {
        $this->requirePermission($request, 'inventory.adjust');
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'], 'code' => ['required', 'string', 'max:50'],
            'zone' => ['nullable', 'string', 'max:80'], 'rack' => ['nullable', 'string', 'max:80'],
            'shelf' => ['nullable', 'string', 'max:80'], 'bin' => ['nullable', 'string', 'max:80'],
        ]);
        abort_unless(Warehouse::query()->whereKey($data['warehouse_id'])->exists(), 404);
        $location = WarehouseLocation::create($data + ['tenant_id' => TenantContext::idOrFail(), 'is_active' => true]);
        $audit->log($location->tenant_id, $request->user()->id, 'inventory.location.created', WarehouseLocation::class, $location->id, null, $location->toArray());

        return back()->with('status', 'Lokasi gudang berhasil dibuat.');
    }

    public function updateLocation(Request $request, WarehouseLocation $location, AuditService $audit): RedirectResponse
    {
        $this->requirePermission($request, 'inventory.adjust');
        $this->assertTenant($location->tenant_id);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'zone' => ['nullable', 'string', 'max:80'], 'rack' => ['nullable', 'string', 'max:80'],
            'shelf' => ['nullable', 'string', 'max:80'], 'bin' => ['nullable', 'string', 'max:80'],
        ]);
        $exists = WarehouseLocation::query()->where('warehouse_id', $location->warehouse_id)->where('code', $data['code'])->whereKeyNot($location->id)->exists();
        abort_if($exists, 422, 'Kode lokasi sudah digunakan di gudang ini.');
        $before = $location->toArray();
        $location->update($data);
        $audit->log($location->tenant_id, $request->user()->id, 'inventory.location.updated', WarehouseLocation::class, $location->id, $before, $location->fresh()->toArray());

        return back()->with('status', 'Lokasi gudang diperbarui.');
    }

    public function deactivateLocation(Request $request, WarehouseLocation $location, AuditService $audit): RedirectResponse
    {
        $this->requirePermission($request, 'inventory.adjust');
        $this->assertTenant($location->tenant_id);
        abort_unless($location->is_active, 422, 'Lokasi sudah nonaktif.');
        $before = $location->toArray();
        $location->update(['is_active' => false]);
        $audit->log($location->tenant_id, $request->user()->id, 'inventory.location.deactivated', WarehouseLocation::class, $location->id, $before, $location->fresh()->toArray());

        return back()->with('status', 'Lokasi dinonaktifkan untuk mutasi stok baru; histori tetap tersedia.');
    }

    public function storeReservation(Request $request, StockReservationService $service): RedirectResponse
    {
        $this->requirePermission($request, 'inventory.transfer');
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'], 'warehouse_location_id' => ['nullable', 'integer'], 'inventory_batch_id' => ['nullable', 'integer'], 'product_variant_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0'], 'source_type' => ['required', 'string', 'max:80'],
            'source_id' => ['nullable', 'integer'], 'expires_at' => ['nullable', 'date', 'after:now'],
        ]);
        $service->reserve($data + ['tenant_id' => TenantContext::idOrFail()], $request->user()->id);

        return back()->with('status', 'Stok berhasil direservasi.');
    }

    public function releaseReservation(Request $request, StockReservation $reservation, StockReservationService $service): RedirectResponse
    {
        $this->requirePermission($request, 'inventory.transfer');
        $this->assertTenant($reservation->tenant_id);
        $service->release($reservation, $request->user()->id);

        return back()->with('status', 'Reservasi berhasil dilepas.');
    }

    public function storeTransfer(Request $request, StockTransferService $service): RedirectResponse
    {
        $this->requirePermission($request, 'inventory.transfer');
        $data = $request->validate([
            'from_warehouse_id' => ['required', 'integer', 'different:to_warehouse_id'],
            'to_warehouse_id' => ['required', 'integer'], 'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_variant_id' => ['required', 'integer'], 'lines.*.inventory_batch_id' => ['nullable', 'integer'],
            'lines.*.source_warehouse_location_id' => ['nullable', 'integer'], 'lines.*.destination_warehouse_location_id' => ['nullable', 'integer'],
            'lines.*.serial_number_ids' => ['nullable', 'array'], 'lines.*.serial_number_ids.*' => ['integer', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $service->createDraft(TenantContext::idOrFail(), (int) $data['from_warehouse_id'], (int) $data['to_warehouse_id'], $data['lines'], $data['notes'] ?? null, $request->user()->id);

        return back()->with('status', 'Draft transfer berhasil dibuat.');
    }

    public function transferAction(Request $request, TransferOrder $transfer, string $action, StockTransferService $service): RedirectResponse
    {
        $this->requirePermission($request, 'inventory.transfer');
        $this->assertTenant($transfer->tenant_id);
        match ($action) {
            'approve' => $service->approve($transfer, $request->user()->id),
            'ship' => $service->ship($transfer, $request->user()->id),
            'transit' => $service->markInTransit($transfer, $request->user()->id),
            'cancel' => $service->cancel($transfer, $request->user()->id),
            default => abort(404),
        };

        return back()->with('status', 'Status transfer berhasil diperbarui.');
    }

    public function receiveTransfer(Request $request, TransferOrder $transfer, StockTransferService $service): RedirectResponse
    {
        $this->requirePermission($request, 'inventory.transfer');
        $this->assertTenant($transfer->tenant_id);
        $data = $request->validate(['quantities' => ['required', 'array'], 'quantities.*' => ['nullable', 'numeric', 'gt:0']]);
        $service->receive($transfer, array_filter($data['quantities'], fn ($quantity) => $quantity !== null && $quantity !== ''), $request->user()->id);

        return back()->with('status', 'Penerimaan transfer berhasil dicatat.');
    }

    public function storeAdjustment(Request $request, StockAdjustmentService $service): RedirectResponse
    {
        $this->requirePermission($request, 'inventory.adjust');
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'], 'reason' => ['required', 'in:damage,expired,loss,count_correction,opening_correction,other'],
            'notes' => ['required', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_variant_id' => ['required', 'integer'],
            'lines.*.quantity_change' => ['required', 'numeric', 'not_in:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);
        $service->create(TenantContext::idOrFail(), (int) $data['warehouse_id'], $data['reason'], $data['lines'], $data['notes'], $request->user()->id);

        return back()->with('status', 'Draft penyesuaian berhasil dibuat.');
    }

    public function adjustmentAction(Request $request, StockAdjustment $adjustment, string $action, StockAdjustmentService $service): RedirectResponse
    {
        $this->requirePermission($request, 'inventory.adjust');
        $this->assertTenant($adjustment->tenant_id);
        match ($action) {
            'approve' => $service->approve($adjustment, $request->user()->id),
            'post' => $service->post($adjustment, $request->user()->id),
            default => abort(404),
        };

        return back()->with('status', 'Status penyesuaian berhasil diperbarui.');
    }

    public function storeCount(Request $request, StockCountService $service): RedirectResponse
    {
        $this->requirePermission($request, 'inventory.adjust');
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'], 'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $service->createAndSnapshot(TenantContext::idOrFail(), (int) $data['warehouse_id'], $data['reference'] ?? null, $data['notes'] ?? null, $request->user()->id);

        return back()->with('status', 'Stock count dan snapshot berhasil dibuat.');
    }

    public function recordCount(Request $request, StockCount $count, StockCountService $service): RedirectResponse
    {
        $this->requirePermission($request, 'inventory.adjust');
        $this->assertTenant($count->tenant_id);
        $data = $request->validate(['quantities' => ['required', 'array'], 'quantities.*' => ['required', 'numeric', 'min:0']]);
        $service->recordCounts($count, $data['quantities'], $request->user()->id);

        return back()->with('status', 'Hasil hitung berhasil dikirim untuk review.');
    }

    public function countAction(Request $request, StockCount $count, string $action, StockCountService $service): RedirectResponse
    {
        $this->requirePermission($request, 'inventory.adjust');
        $this->assertTenant($count->tenant_id);
        match ($action) {
            'approve' => $service->approve($count, $request->user()->id),
            'post' => $service->post($count, $request->user()->id),
            default => abort(404),
        };

        return back()->with('status', 'Status stock count berhasil diperbarui.');
    }

    private function requirePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), 403);
    }

    private function assertTenant(int $tenantId): void
    {
        abort_unless($tenantId === TenantContext::idOrFail(), 404);
    }
}
