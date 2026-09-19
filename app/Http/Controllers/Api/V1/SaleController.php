<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Services\SaleService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            SalesInvoice::with(['lines', 'payments'])->orderByDesc('id')->paginate($request->get('per_page', 15))
        );
    }

    public function store(Request $request, SaleService $service)
    {
        $tenantId = TenantContext::idOrFail();
        $data = $request->validate([
            'branch_id' => ['required', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'contact_id' => ['nullable', Rule::exists('contacts', 'id')->where('tenant_id', $tenantId)],
            'lines' => 'required|array|min:1',
            'lines.*.variant_id' => ['required', Rule::exists('product_variants', 'id')->where('tenant_id', $tenantId)],
            'lines.*.inventory_batch_id' => ['nullable', Rule::exists('inventory_batches', 'id')->where('tenant_id', $tenantId)],
            'lines.*.serial_number_ids' => ['nullable', 'array'],
            'lines.*.serial_number_ids.*' => ['integer', 'distinct', Rule::exists('serial_numbers', 'id')->where('tenant_id', $tenantId)],
            'lines.*.quantity' => 'required|numeric|min:0.001',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'payments' => 'required|array|min:1',
            'payments.*.method' => 'required|in:cash,transfer,qris,ewallet,card',
            'payments.*.amount' => 'required|numeric|min:0',
        ]);

        $key = $request->header('Idempotency-Key', (string) \Str::uuid());

        $invoice = $service->checkout(
            $tenantId,
            $data['branch_id'], $data['warehouse_id'], $data['contact_id'] ?? null,
            $data['lines'], $data['payments'], $key
        );

        return response()->json($invoice->load(['lines', 'payments']), 201);
    }

    public function void(SalesInvoice $invoice, SaleService $service, Request $request)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $service->void($invoice->id, $request->user()->can('pos.sale.void'), TenantContext::idOrFail(), $data['reason']);

        return response()->json(['ok' => true]);
    }

    public function storeReturn(SalesInvoice $invoice, SaleService $service, Request $request)
    {
        $tenantId = TenantContext::idOrFail();
        $data = $request->validate([
            'lines' => 'required|array|min:1',
            'lines.*.variant_id' => ['required', Rule::exists('product_variants', 'id')->where('tenant_id', $tenantId)],
            'lines.*.quantity' => 'required|numeric|min:0.001',
            'lines.*.unit_price' => 'nullable|numeric|min:0',
            'lines.*.inventory_batch_id' => ['nullable', Rule::exists('inventory_batches', 'id')->where('tenant_id', $tenantId)],
            'lines.*.serial_number_ids' => ['nullable', 'array'],
            'lines.*.serial_number_ids.*' => ['integer', 'distinct', Rule::exists('serial_numbers', 'id')->where('tenant_id', $tenantId)],
            'reason' => ['required', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ]);

        return response()->json($service->return(
            $invoice->id, $data['lines'], $tenantId, $request->user()->id,
            $data['idempotency_key'] ?? $request->header('Idempotency-Key'),
            $data['reason'], $request->user()->can('pos.sale.create')
        )->load('lines'), 201);
    }

    public function storeRefund(SalesReturn $salesReturn, SaleService $service, Request $request)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', 'in:cash,transfer,qris,ewallet,card'],
            'reference' => ['nullable', 'string', 'max:100'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return response()->json($service->refund(
            $salesReturn->sales_invoice_id, $salesReturn->id, (float) $data['amount'], $data['method'],
            $data['reference'] ?? $request->header('Idempotency-Key'), $data['reason'], $request->user()->id,
            $request->user()->can('pos.sale.void'), TenantContext::idOrFail()
        ), 201);
    }
}
