<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\SupplierInvoice;
use App\Services\PurchaseService;
use App\Services\SupplierDocumentService;
use App\Services\UnitConversionService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PurchaseController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            Purchase::with('lines')->orderByDesc('id')->paginate($request->get('per_page', 15))
        );
    }

    public function store(Request $request, PurchaseService $service, UnitConversionService $conversions)
    {
        $tenantId = TenantContext::idOrFail();
        $data = $request->validate([
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'contact_id' => ['required', Rule::exists('contacts', 'id')->where('tenant_id', $tenantId)],
            'lines' => 'required|array|min:1',
            'lines.*.product_variant_id' => ['required', Rule::exists('product_variants', 'id')->where('tenant_id', $tenantId)],
            'lines.*.unit_id' => ['nullable', Rule::exists('units', 'id')->where('tenant_id', $tenantId)],
            'lines.*.quantity' => 'required|numeric|min:0.001',
            'lines.*.unit_cost' => 'required|numeric|min:0',
        ]);

        $lines = collect($data['lines'])->map(function (array $line) use ($tenantId, $conversions) {
            if (empty($line['unit_id'])) {
                return $line;
            }
            $variant = ProductVariant::withoutGlobalScopes()->with('product')->findOrFail($line['product_variant_id']);
            abort_unless($variant->product?->unit_id, 422, 'Product must have a base unit.');
            $factor = $conversions->convert($tenantId, 1, (int) $line['unit_id'], (int) $variant->product->unit_id);
            $line['quantity'] = $conversions->convert($tenantId, $line['quantity'], (int) $line['unit_id'], (int) $variant->product->unit_id);
            $line['unit_cost'] = round((float) $line['unit_cost'] / $factor, 2);
            unset($line['unit_id']);

            return $line;
        })->all();
        $purchase = $service->createDraft(
            $tenantId,
            $data['warehouse_id'], $data['contact_id'], $lines, $request->user()->id
        );

        return response()->json($purchase->load('lines'), 201);
    }

    public function receive(Request $request, Purchase $purchase, PurchaseService $service)
    {
        $data = $request->validate([
            'lines' => 'sometimes|array',
            'lines.*.product_variant_id' => 'required_with:lines|integer',
            'lines.*.quantity' => 'required_with:lines|numeric|min:0.001',
            'lines.*.warehouse_location_id' => ['nullable', 'integer'],
        ]);

        return response()->json($service->receive(
            $purchase->id, $data['lines'] ?? null, TenantContext::idOrFail(), $request->user()?->id
        )->load(['lines', 'goodsReceipts.lines']));
    }

    public function storeSupplierInvoice(Request $request, SupplierDocumentService $service)
    {
        abort_unless($request->user()->can('purchase.create'), 403);
        $data = $request->validate([
            'purchase_id' => ['nullable', 'integer'], 'supplier_id' => ['nullable', 'integer'],
            'invoice_number' => ['required', 'string', 'max:100'], 'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'], 'subtotal' => ['required', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'], 'tax' => ['nullable', 'numeric', 'min:0'],
            'shipping' => ['nullable', 'numeric', 'min:0'],
        ]);

        return response()->json($service->createInvoice(TenantContext::idOrFail(), $data, $request->user()->id), 201);
    }

    public function paySupplierInvoice(Request $request, SupplierInvoice $invoice, SupplierDocumentService $service)
    {
        abort_unless($request->user()->can('purchase.approve'), 403);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'], 'method' => ['required', 'string', 'max:40'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json($service->pay($invoice, (float) $data['amount'], $data['method'], $data['reference'] ?? null, $request->user()->id));
    }

    public function storeReturn(Request $request, Purchase $purchase, SupplierDocumentService $service)
    {
        abort_unless($request->user()->can('purchase.approve'), 403);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'settlement_type' => ['required', 'in:supplier_credit,cash_refund,replacement'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.purchase_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.inventory_batch_id' => ['nullable', 'integer'],
            'lines.*.warehouse_location_id' => ['nullable', 'integer'],
            'lines.*.serial_number_id' => ['nullable', 'integer'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'tax' => ['nullable', 'numeric', 'min:0'], 'discount' => ['nullable', 'numeric', 'min:0'],
        ]);

        return response()->json($service->createReturn(
            $purchase, $data['lines'], $data['reason'], $data['settlement_type'], $request->user()->id,
            $data['idempotency_key'] ?? $request->header('Idempotency-Key'),
            (float) ($data['tax'] ?? 0), (float) ($data['discount'] ?? 0)
        ), 201);
    }

    public function storeDraftReturn(Request $request, Purchase $purchase, SupplierDocumentService $service)
    {
        abort_unless($request->user()->can('purchase.create'), 403);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'settlement_type' => ['required', 'in:supplier_credit,cash_refund,replacement'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.purchase_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.inventory_batch_id' => ['nullable', 'integer'],
            'lines.*.warehouse_location_id' => ['nullable', 'integer'],
            'lines.*.serial_number_id' => ['nullable', 'integer'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'tax' => ['nullable', 'numeric', 'min:0'], 'discount' => ['nullable', 'numeric', 'min:0'],
        ]);

        return response()->json($service->createDraft(
            $purchase, $data['lines'], $data['reason'], $data['settlement_type'], $request->user()->id,
            $data['idempotency_key'] ?? $request->header('Idempotency-Key'),
            (float) ($data['tax'] ?? 0), (float) ($data['discount'] ?? 0)
        ), 201);
    }

    public function submitDraftReturn(Request $request, PurchaseReturn $purchaseReturn, SupplierDocumentService $service)
    {
        abort_unless($request->user()->can('purchase.create'), 403);

        return response()->json($service->submitReturn($purchaseReturn, $request->user()->id)->load('lines'));
    }

    public function approveDraftReturn(Request $request, PurchaseReturn $purchaseReturn, SupplierDocumentService $service)
    {
        abort_unless($request->user()->can('purchase.approve'), 403);

        return response()->json($service->approveReturn($purchaseReturn, $request->user()->id)->load('lines'));
    }

    public function postDraftReturn(Request $request, PurchaseReturn $purchaseReturn, SupplierDocumentService $service)
    {
        abort_unless($request->user()->can('purchase.approve'), 403);

        return response()->json($service->postReturn($purchaseReturn, $request->user()->id)->load('lines'));
    }
}
