<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\InventoryBatch;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\SupplierInvoice;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\PurchaseService;
use App\Services\SupplierDocumentService;
use App\Services\UnitConversionService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PurchasingWorkspaceController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeView($request);
        $locations = WarehouseLocation::query()->where('is_active', true)->orderBy('code')->get();
        $purchases = Purchase::query()->with(['contact', 'warehouse', 'lines.variant.product', 'goodsReceipts.lines'])->latest()->limit(30)->get();

        return view('purchasing.index', [
            'warehouses' => Warehouse::query()->orderBy('name')->get(),
            'locations' => $locations,
            'receiptLocationsJson' => $locations->map(fn (WarehouseLocation $location) => [
                'id' => $location->id, 'warehouse_id' => $location->warehouse_id, 'code' => $location->code,
            ])->values()->toJson(),
            'purchaseWarehousesJson' => $purchases->mapWithKeys(fn (Purchase $purchase) => [$purchase->id => $purchase->warehouse_id])->toJson(),
            'suppliers' => Contact::query()->whereIn('type', ['supplier', 'both'])->orderBy('name')->get(),
            'variants' => ProductVariant::query()->with('product.unit')->orderBy('sku')->get(),
            'batches' => InventoryBatch::query()->orderBy('batch_number')->get(),
            'units' => Unit::query()->where('is_active', true)->orderBy('name')->get(),
            'purchases' => $purchases,
            'invoices' => SupplierInvoice::query()->with(['supplier', 'purchase', 'payments'])->latest()->limit(30)->get(),
            'returns' => PurchaseReturn::query()->with(['purchase.contact', 'lines.variant.product'])->latest()->limit(30)->get(),
        ]);
    }

    public function storePurchase(Request $request, PurchaseService $service, UnitConversionService $conversions): RedirectResponse
    {
        abort_unless($request->user()->can('purchase.create'), 403);
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'], 'contact_id' => ['required', 'integer'],
            'product_variant_id' => ['required_without:lines', 'integer'], 'quantity' => ['required_without:lines', 'numeric', 'gt:0'],
            'unit_id' => ['required_without:lines', 'integer'],
            'unit_cost' => ['required_without:lines', 'numeric', 'min:0'],
            'lines' => ['nullable', 'array', 'min:1'],
            'lines.*.product_variant_id' => ['required_with:lines', 'integer'], 'lines.*.quantity' => ['required_with:lines', 'numeric', 'gt:0'],
            'lines.*.unit_id' => ['required_with:lines', 'integer'], 'lines.*.unit_cost' => ['required_with:lines', 'numeric', 'min:0'],
            'save_as' => ['nullable', 'in:draft,submit'],
        ]);
        $lines = $this->normalizedLines($data['lines'] ?? [[
            'product_variant_id' => $data['product_variant_id'], 'quantity' => $data['quantity'], 'unit_id' => $data['unit_id'], 'unit_cost' => $data['unit_cost'],
        ]], $conversions);
        $purchase = $service->createDraft(TenantContext::idOrFail(), (int) $data['warehouse_id'], (int) $data['contact_id'], $lines, $request->user()->id, ($data['save_as'] ?? 'submit') === 'draft');

        return back()->with('status', $purchase->status === 'draft' ? 'PO draft disimpan.' : ($purchase->status === 'pending_approval' ? 'PO dibuat dan menunggu approval.' : 'PO berhasil dibuat.'));
    }

    public function editPurchase(Request $request, Purchase $purchase): View
    {
        abort_unless($request->user()->can('purchase.create'), 403);
        $this->assertTenant($purchase->tenant_id);
        abort_unless($purchase->status === 'draft' && $purchase->requested_by === $request->user()->id, 422);

        return $this->purchaseForm($purchase->load('lines.variant.product.unit'));
    }

    public function createPurchase(Request $request): View
    {
        abort_unless($request->user()->can('purchase.create'), 403);

        return $this->purchaseForm();
    }

    public function updatePurchase(Request $request, Purchase $purchase, PurchaseService $service, UnitConversionService $conversions): RedirectResponse
    {
        abort_unless($request->user()->can('purchase.create'), 403);
        $this->assertTenant($purchase->tenant_id);
        $data = $request->validate(['warehouse_id' => ['required', 'integer'], 'contact_id' => ['required', 'integer'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.product_variant_id' => ['required', 'integer'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.unit_id' => ['required', 'integer'], 'lines.*.unit_cost' => ['required', 'numeric', 'min:0']]);
        $service->updateDraft($purchase, (int) $data['warehouse_id'], (int) $data['contact_id'], $this->normalizedLines($data['lines'], $conversions), $request->user()->id);

        return redirect()->route('purchasing.index')->with('status', 'PO draft diperbarui.');
    }

    public function submitPurchase(Request $request, Purchase $purchase, PurchaseService $service): RedirectResponse
    {
        abort_unless($request->user()->can('purchase.create'), 403);
        $this->assertTenant($purchase->tenant_id);
        $service->submitDraft($purchase, $request->user()->id);

        return back()->with('status', 'PO dikirim untuk approval atau siap diterima.');
    }

    public function cancelPurchase(Request $request, Purchase $purchase, PurchaseService $service): RedirectResponse
    {
        abort_unless($request->user()->can('purchase.create'), 403);
        $this->assertTenant($purchase->tenant_id);
        $service->cancel($purchase, $request->user()->id);

        return back()->with('status', 'PO dibatalkan tanpa mengubah stok.');
    }

    public function printPurchase(Request $request, Purchase $purchase): View
    {
        abort_unless($request->user()->can('purchase.create') || $request->user()->can('purchase.approve'), 403);
        $this->assertTenant($purchase->tenant_id);

        return view('purchasing.purchase-order-print', [
            'purchase' => $purchase->load(['contact', 'warehouse.branch', 'lines.variant.product.unit']),
        ]);
    }

    public function receive(Request $request, Purchase $purchase, PurchaseService $service): RedirectResponse
    {
        abort_unless($request->user()->can('purchase.create'), 403);
        $this->assertTenant($purchase->tenant_id);
        $data = $request->validate([
            'lines' => ['required', 'array'],
            'lines.*' => ['nullable', 'numeric', 'gt:0'],
            'batch_ids' => ['nullable', 'array'],
            'batch_ids.*' => ['nullable', 'integer'],
            'batch_numbers' => ['nullable', 'array'],
            'batch_numbers.*' => ['nullable', 'string', 'max:128'],
            'manufactured_at' => ['nullable', 'array'],
            'manufactured_at.*' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'array'],
            'expires_at.*' => ['nullable', 'date'],
            'serial_numbers' => ['nullable', 'array'],
            'serial_numbers.*' => ['nullable', 'string', 'max:4000'],
            'warehouse_location_id' => ['nullable', 'integer'],
        ]);
        $lines = collect($data['lines'])->filter(fn ($quantity) => $quantity !== null && $quantity !== '')
            ->map(fn ($quantity, $variantId) => [
                'product_variant_id' => (int) $variantId,
                'quantity' => $quantity,
                'inventory_batch_id' => filled($data['batch_ids'][$variantId] ?? null) ? (int) $data['batch_ids'][$variantId] : null,
                'batch_number' => $data['batch_numbers'][$variantId] ?? null,
                'manufactured_at' => $data['manufactured_at'][$variantId] ?? null,
                'expires_at' => $data['expires_at'][$variantId] ?? null,
                'serial_numbers' => preg_split('/[\\s,]+/', trim((string) ($data['serial_numbers'][$variantId] ?? '')), -1, PREG_SPLIT_NO_EMPTY),
                'warehouse_location_id' => $data['warehouse_location_id'] ?? null,
            ])->values()->all();
        $service->receive($purchase->id, $lines, TenantContext::idOrFail(), $request->user()->id);

        return back()->with('status', 'Goods Receipt berhasil diposting.');
    }

    public function storeInvoice(Request $request, SupplierDocumentService $service): RedirectResponse
    {
        abort_unless($request->user()->can('purchase.create'), 403);
        $data = $request->validate([
            'purchase_id' => ['required', 'integer'], 'supplier_id' => ['required', 'integer'],
            'invoice_number' => ['required', 'string', 'max:100'], 'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'], 'subtotal' => ['required', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'], 'tax' => ['nullable', 'numeric', 'min:0'], 'shipping' => ['nullable', 'numeric', 'min:0'],
        ]);
        $service->createInvoice(TenantContext::idOrFail(), $data, $request->user()->id);

        return back()->with('status', 'Invoice pemasok berhasil dicatat.');
    }

    public function pay(Request $request, SupplierInvoice $invoice, SupplierDocumentService $service): RedirectResponse
    {
        abort_unless($request->user()->can('purchase.approve'), 403);
        $this->assertTenant($invoice->tenant_id);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'], 'method' => ['required', 'string', 'max:40'], 'reference' => ['nullable', 'string', 'max:100'],
        ]);
        $service->pay($invoice, (float) $data['amount'], $data['method'], $data['reference'] ?? null, $request->user()->id);

        return back()->with('status', 'Pembayaran pemasok berhasil dicatat.');
    }

    public function printInvoice(Request $request, SupplierInvoice $invoice): View
    {
        abort_unless($request->user()->can('purchase.create') || $request->user()->can('purchase.approve'), 403);
        $this->assertTenant($invoice->tenant_id);

        return view('purchasing.supplier-invoice-print', [
            'invoice' => $invoice->load(['supplier', 'purchase', 'payments.creator']),
        ]);
    }

    public function createReturn(Request $request, Purchase $purchase): View
    {
        abort_unless($request->user()->can('purchase.approve'), 403);
        $this->assertTenant($purchase->tenant_id);
        abort_unless(in_array($purchase->status, ['partial', 'received'], true), 422);

        return view('purchasing.return-form', [
            'purchase' => $purchase->load(['contact', 'warehouse', 'lines.variant.product', 'lines.goodsReceiptLines.batch', 'lines.goodsReceiptLines.warehouseLocation']),
        ]);
    }

    public function storeReturn(Request $request, Purchase $purchase, SupplierDocumentService $service): RedirectResponse
    {
        abort_unless($request->user()->can('purchase.approve'), 403);
        $this->assertTenant($purchase->tenant_id);
        $data = $request->validate([
            'purchase_line_id' => ['required_without:lines', 'integer'], 'quantity' => ['required_without:lines', 'numeric', 'gt:0'],
            'inventory_batch_id' => ['nullable', 'integer'], 'warehouse_location_id' => ['nullable', 'integer'],
            'serial_number_id' => ['nullable', 'integer'],
            'lines' => ['nullable', 'array', 'min:1'],
            'lines.*.purchase_line_id' => ['required_with:lines', 'integer'],
            'lines.*.quantity' => ['required_with:lines', 'numeric', 'gt:0'],
            'lines.*.inventory_batch_id' => ['nullable', 'integer'],
            'lines.*.warehouse_location_id' => ['nullable', 'integer'],
            'lines.*.serial_number_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'max:1000'], 'settlement_type' => ['required', 'in:supplier_credit,cash_refund,replacement'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'tax' => ['nullable', 'numeric', 'min:0'], 'discount' => ['nullable', 'numeric', 'min:0'],
        ]);
        $lines = $data['lines'] ?? [[
            'purchase_line_id' => $data['purchase_line_id'], 'quantity' => $data['quantity'],
            'inventory_batch_id' => $data['inventory_batch_id'] ?? null,
            'warehouse_location_id' => $data['warehouse_location_id'] ?? null,
            'serial_number_id' => $data['serial_number_id'] ?? null,
        ]];
        $service->createReturn(
            $purchase, $lines, $data['reason'], $data['settlement_type'], $request->user()->id,
            $data['idempotency_key'] ?? null, (float) ($data['tax'] ?? 0), (float) ($data['discount'] ?? 0)
        );

        return back()->with('status', 'Purchase return berhasil diposting.');
    }

    public function storeDraftReturn(Request $request, Purchase $purchase, SupplierDocumentService $service): RedirectResponse
    {
        abort_unless($request->user()->can('purchase.create'), 403);
        $this->assertTenant($purchase->tenant_id);
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.inventory_batch_id' => ['nullable', 'integer'],
            'lines.*.warehouse_location_id' => ['nullable', 'integer'],
            'lines.*.serial_number_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'max:1000'], 'settlement_type' => ['required', 'in:supplier_credit,cash_refund,replacement'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'tax' => ['nullable', 'numeric', 'min:0'], 'discount' => ['nullable', 'numeric', 'min:0'],
        ]);
        $return = $service->createDraft(
            $purchase, $data['lines'], $data['reason'], $data['settlement_type'], $request->user()->id,
            $data['idempotency_key'] ?? null, (float) ($data['tax'] ?? 0), (float) ($data['discount'] ?? 0)
        );

        return redirect()->route('purchasing.returns.show', $return)->with('status', 'Purchase return draft disimpan.');
    }

    public function showReturn(Request $request, PurchaseReturn $purchaseReturn): View
    {
        abort_unless($request->user()->can('purchase.create') || $request->user()->can('purchase.approve'), 403);
        $this->assertTenant($purchaseReturn->tenant_id);

        return view('purchasing.return-show', [
            'purchaseReturn' => $purchaseReturn->load(['purchase.contact', 'purchase.warehouse', 'lines.variant.product', 'lines.batch', 'lines.warehouseLocation']),
        ]);
    }

    public function submitReturn(Request $request, PurchaseReturn $purchaseReturn, SupplierDocumentService $service): RedirectResponse
    {
        abort_unless($request->user()->can('purchase.create'), 403);
        $this->assertTenant($purchaseReturn->tenant_id);
        $service->submitReturn($purchaseReturn, $request->user()->id);

        return back()->with('status', 'Purchase return dikirim untuk approval.');
    }

    public function approveReturn(Request $request, PurchaseReturn $purchaseReturn, SupplierDocumentService $service): RedirectResponse
    {
        abort_unless($request->user()->can('purchase.approve'), 403);
        $this->assertTenant($purchaseReturn->tenant_id);
        $service->approveReturn($purchaseReturn, $request->user()->id);

        return back()->with('status', 'Purchase return disetujui.');
    }

    public function postReturn(Request $request, PurchaseReturn $purchaseReturn, SupplierDocumentService $service): RedirectResponse
    {
        abort_unless($request->user()->can('purchase.approve'), 403);
        $this->assertTenant($purchaseReturn->tenant_id);
        $service->postReturn($purchaseReturn, $request->user()->id);

        return back()->with('status', 'Purchase return diposting dan tidak dapat diubah.');
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()->can('purchase.create') || $request->user()->can('purchase.approve'), 403);
    }

    private function assertTenant(int $tenantId): void
    {
        abort_unless($tenantId === TenantContext::idOrFail(), 404);
    }

    private function normalizedLines(array $lines, UnitConversionService $conversions): array
    {
        return collect($lines)->map(function (array $line) use ($conversions) {
            $variant = ProductVariant::query()->with('product')->findOrFail($line['product_variant_id']);
            abort_unless($variant->product?->unit_id, 422, 'Produk harus memiliki satuan dasar.');
            $factor = $conversions->convert(TenantContext::idOrFail(), 1, (int) $line['unit_id'], (int) $variant->product->unit_id);

            return ['product_variant_id' => (int) $line['product_variant_id'], 'quantity' => $conversions->convert(TenantContext::idOrFail(), $line['quantity'], (int) $line['unit_id'], (int) $variant->product->unit_id), 'unit_cost' => round((float) $line['unit_cost'] / $factor, 2)];
        })->values()->all();
    }

    private function purchaseForm(?Purchase $purchase = null): View
    {
        return view('purchasing.form', [
            'purchase' => $purchase,
            'warehouses' => Warehouse::query()->orderBy('name')->get(),
            'suppliers' => Contact::query()->whereIn('type', ['supplier', 'both'])->orderBy('name')->get(),
            'variants' => ProductVariant::query()->with('product.unit')->orderBy('sku')->get(),
            'units' => Unit::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }
}
