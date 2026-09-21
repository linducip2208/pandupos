<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\ProductVariant;
use App\Models\SalePayment;
use App\Models\SalesDelivery;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\SalesQuotation;
use App\Models\Warehouse;
use App\Services\AccountingService;
use App\Services\ModuleRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SalesOrderService
{
    public function __construct(
        private StockReservationService $reservations,
        private AuditService $audit,
        private UnitConversionService $conversions,
    ) {}

    public function create(int $tenantId, array $data, int $actorId): SalesOrder
    {
        $this->validateReferences($tenantId, $data);
        $data['lines'] = collect($data['lines'])->map(function (array $line) use ($tenantId) {
            if (empty($line['unit_id'])) {
                return $line;
            }
            $variant = ProductVariant::withoutGlobalScopes()->with('product')->findOrFail($line['product_variant_id']);
            abort_unless($variant->product?->unit_id, 422, 'Product must have a base unit.');
            $factor = $this->conversions->convert($tenantId, 1, (int) $line['unit_id'], (int) $variant->product->unit_id);
            $line['quantity'] = $this->conversions->convert($tenantId, $line['quantity'], (int) $line['unit_id'], (int) $variant->product->unit_id);
            $line['unit_price'] = round((float) $line['unit_price'] / $factor, 2);
            unset($line['unit_id']);

            return $line;
        })->all();

        return DB::transaction(function () use ($tenantId, $data, $actorId) {
            $total = collect($data['lines'])->sum(fn ($line) => (float) $line['quantity'] * (float) $line['unit_price'] - (float) ($line['discount'] ?? 0));
            $order = SalesOrder::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId, 'branch_id' => $data['branch_id'], 'warehouse_id' => $data['warehouse_id'],
                'contact_id' => $data['contact_id'], 'sales_quotation_id' => $data['sales_quotation_id'] ?? null,
                'order_no' => 'SO-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)), 'status' => 'draft',
                'order_date' => $data['order_date'] ?? today(), 'total' => $total, 'notes' => $data['notes'] ?? null, 'created_by' => $actorId,
            ]);
            foreach ($data['lines'] as $line) {
                $order->lines()->create($line + ['fulfilled_quantity' => 0, 'discount' => $line['discount'] ?? 0]);
            }
            $this->audit->log($tenantId, $actorId, 'sales_order.created', 'sales_order', $order->id, null, $order->toArray());

            return $order->load('lines');
        });
    }

    public function confirm(SalesOrder $order, int $actorId): SalesOrder
    {
        abort_unless($order->status === 'draft', 422, 'Only draft order can be confirmed.');

        return DB::transaction(function () use ($order, $actorId) {
            $locked = SalesOrder::withoutGlobalScopes()->with('lines')->lockForUpdate()->findOrFail($order->id);
            abort_unless($locked->status === 'draft', 422, 'Only draft order can be confirmed.');
            foreach ($locked->lines as $line) {
                $this->reservations->reserve([
                    'tenant_id' => $locked->tenant_id, 'warehouse_id' => $locked->warehouse_id,
                    'product_variant_id' => $line->product_variant_id, 'quantity' => $line->quantity,
                    'source_type' => 'sales_order_line', 'source_id' => $line->id,
                    'idempotency_key' => 'sales-order-line-'.$line->id,
                ], $actorId);
            }
            $locked->update(['status' => 'confirmed']);
            $this->audit->log($locked->tenant_id, $actorId, 'sales_order.confirmed', 'sales_order', $locked->id);

            return $locked->fresh('lines.reservation');
        });
    }

    public function deliver(SalesOrder $order, array $lines, int $actorId, ?string $tracking = null): SalesDelivery
    {
        abort_unless(in_array($order->status, ['confirmed', 'partial'], true), 422, 'Order is not deliverable.');

        return DB::transaction(function () use ($order, $lines, $actorId, $tracking) {
            $locked = SalesOrder::withoutGlobalScopes()->with('lines.reservation')->lockForUpdate()->findOrFail($order->id);
            $delivery = SalesDelivery::withoutGlobalScopes()->create([
                'tenant_id' => $locked->tenant_id, 'sales_order_id' => $locked->id,
                'delivery_no' => 'DO-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'delivery_date' => today(), 'tracking_reference' => $tracking, 'delivered_by' => $actorId,
            ]);
            foreach ($lines as $row) {
                $line = $locked->lines->firstWhere('id', (int) $row['sales_order_line_id']);
                abort_unless($line, 422, 'Delivery line does not belong to order.');
                $remaining = (float) $line->quantity - (float) $line->fulfilled_quantity;
                $quantity = (float) $row['quantity'];
                abort_if($quantity <= 0 || $quantity > $remaining + 0.000001, 422, 'Delivery quantity exceeds outstanding order quantity.');
                abort_unless($line->reservation, 422, 'Active reservation is missing.');
                $delivery->lines()->create(['sales_order_line_id' => $line->id, 'quantity' => $quantity]);
                $this->reservations->consumeQuantity($line->reservation, $quantity, 'sales_delivery', $delivery->id, $actorId);
                $line->increment('fulfilled_quantity', $quantity);
            }
            $fulfilled = $locked->fresh('lines')->lines->every(fn ($line) => (float) $line->fulfilled_quantity >= (float) $line->quantity - 0.000001);
            $locked->update(['status' => $fulfilled ? 'fulfilled' : 'partial']);
            $this->audit->log($locked->tenant_id, $actorId, 'sales_delivery.posted', 'sales_delivery', $delivery->id, null, $delivery->toArray());

            return $delivery->load('lines');
        });
    }

    public function cancel(SalesOrder $order, int $actorId): SalesOrder
    {
        abort_unless(in_array($order->status, ['draft', 'confirmed'], true), 422, 'Only unfulfilled order can be cancelled.');

        return DB::transaction(function () use ($order, $actorId) {
            $locked = SalesOrder::withoutGlobalScopes()->with('lines.reservation')->lockForUpdate()->findOrFail($order->id);
            abort_unless(in_array($locked->status, ['draft', 'confirmed'], true), 422, 'Only unfulfilled order can be cancelled.');
            foreach ($locked->lines as $line) {
                if ($line->reservation?->status === 'active') {
                    $this->reservations->release($line->reservation, $actorId);
                }
            }
            $locked->update(['status' => 'cancelled']);
            $this->audit->log($locked->tenant_id, $actorId, 'sales_order.cancelled', 'sales_order', $locked->id);

            return $locked->fresh();
        });
    }

    /**
     * Turns a completely fulfilled order into one immutable, unpaid sales invoice.
     * Delivery already consumed stock, so this method never writes inventory.
     */
    public function invoice(SalesOrder $order, int $actorId): SalesInvoice
    {
        return DB::transaction(function () use ($order, $actorId) {
            $locked = SalesOrder::withoutGlobalScopes()->with('lines')->lockForUpdate()->findOrFail($order->id);
            abort_unless($locked->status === 'fulfilled', 422, 'Only a fully delivered sales order can be invoiced.');

            $existing = SalesInvoice::withoutGlobalScopes()
                ->where('tenant_id', $locked->tenant_id)
                ->where('sales_order_id', $locked->id)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing;
            }

            $subtotal = round((float) $locked->lines->sum(fn ($line) => (float) $line->quantity * (float) $line->unit_price), 2);
            $discount = round((float) $locked->lines->sum('discount'), 2);
            $total = round($subtotal - $discount, 2);
            abort_if($total < 0, 422, 'Invoice total cannot be negative.');

            $invoice = SalesInvoice::withoutGlobalScopes()->create([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $locked->tenant_id,
                'branch_id' => $locked->branch_id,
                'warehouse_id' => $locked->warehouse_id,
                'sales_order_id' => $locked->id,
                'contact_id' => $locked->contact_id,
                'invoice_no' => 'INV-SO-'.str_pad((string) $locked->id, 8, '0', STR_PAD_LEFT),
                'status' => 'final',
                'fulfillment_status' => 'fulfilled',
                'payment_status' => $total == 0.0 ? 'paid' : 'unpaid',
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax' => 0,
                'total' => $total,
                'idempotency_key' => 'sales-order-'.$locked->id,
            ]);
            foreach ($locked->lines as $line) {
                $invoice->lines()->create([
                    'product_variant_id' => $line->product_variant_id,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'discount' => $line->discount,
                ]);
            }
            $this->audit->log($locked->tenant_id, $actorId, 'sales_invoice.created_from_order', SalesInvoice::class, $invoice->id, null, [
                'sales_order_id' => $locked->id,
                'invoice_no' => $invoice->invoice_no,
                'total' => $invoice->total,
            ]);
            $this->postInvoiceToAccounting($locked->tenant_id, $invoice, $actorId);

            return $invoice->load('lines');
        });
    }

    /** Record a payment against an immutable posted order invoice. */
    public function payInvoice(SalesInvoice $invoice, float $amount, string $method, ?string $reference, int $actorId): SalePayment
    {
        return DB::transaction(function () use ($invoice, $amount, $method, $reference, $actorId) {
            $locked = SalesInvoice::withoutGlobalScopes()->lockForUpdate()->findOrFail($invoice->id);
            abort_unless($locked->status === 'final', 422, 'Only a posted invoice can receive payment.');
            $amount = round($amount, 2);
            $reference = filled($reference) ? trim($reference) : null;
            $paid = round((float) $locked->payments()->sum('amount'), 2);
            $remaining = round((float) $locked->total - $paid, 2);
            abort_if($amount <= 0 || $amount > $remaining, 422, 'Payment must be positive and cannot exceed invoice balance.');
            if ($reference !== null && SalePayment::withoutGlobalScopes()->where('tenant_id', $locked->tenant_id)->where('reference', $reference)->exists()) {
                abort(422, 'Payment reference has already been recorded.');
            }

            $payment = $locked->payments()->create([
                'tenant_id' => $locked->tenant_id,
                'method' => $method,
                'amount' => $amount,
                'reference' => $reference,
            ]);
            $newPaid = round($paid + $amount, 2);
            $locked->update(['payment_status' => $newPaid >= (float) $locked->total ? 'paid' : 'partial']);
            $this->audit->log($locked->tenant_id, $actorId, 'sales_invoice.payment.recorded', SalePayment::class, $payment->id, null, [
                'sales_invoice_id' => $locked->id,
                'amount' => $payment->amount,
                'reference' => $payment->reference,
                'payment_status' => $locked->fresh()->payment_status,
            ]);
            $this->postReceiptToAccounting($locked->tenant_id, $payment, $actorId);

            return $payment;
        });
    }

    /** Post a customer receipt inside the payment transaction when accounting is enabled. */
    private function postReceiptToAccounting(int $tenantId, SalePayment $payment, int $actorId): void
    {
        if (! app(ModuleRegistry::class)->isEnabled($tenantId, 'accounting')) {
            return;
        }
        app(AccountingService::class)->recordCustomerReceipt($payment, $actorId);
    }

    /** Post revenue + COGS for an invoice created from a fulfilled sales order. */
    private function postInvoiceToAccounting(int $tenantId, SalesInvoice $invoice, int $actorId): void
    {
        if (! app(ModuleRegistry::class)->isEnabled($tenantId, 'accounting')) {
            return;
        }
        $accounting = app(AccountingService::class);
        $accounting->postSalesInvoice($invoice, $actorId);
        $accounting->postSaleCogs($invoice, $actorId);
    }

    private function validateReferences(int $tenantId, array $data): void
    {
        abort_unless(Branch::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($data['branch_id'])->exists(), 422);
        abort_unless(Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($data['warehouse_id'])->exists(), 422);
        abort_unless(Contact::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereIn('type', ['customer', 'both'])->whereKey($data['contact_id'])->exists(), 422);
        if (! empty($data['sales_quotation_id'])) {
            abort_unless(SalesQuotation::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('status', 'accepted')->whereKey($data['sales_quotation_id'])->exists(), 422, 'Accepted quotation does not belong to tenant.');
        }
        foreach ($data['lines'] as $line) {
            abort_unless(ProductVariant::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($line['product_variant_id'])->exists(), 422);
            abort_if((float) $line['quantity'] <= 0 || (float) $line['unit_price'] < 0, 422);
        }
    }
}
