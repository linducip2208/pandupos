<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\ProductVariant;
use App\Models\ProformaInvoice;
use App\Models\SalesQuotation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SalesDocumentService
{
    public function __construct(private AuditService $audit) {}

    public function createQuotation(int $tenantId, array $data, int $actorId): SalesQuotation
    {
        return DB::transaction(function () use ($tenantId, $data, $actorId) {
            $this->validateReferences($tenantId, $data['branch_id'], $data['contact_id'], $data['lines']);
            [$subtotal, $discount, $tax, $total] = $this->totals($data);
            $quotation = SalesQuotation::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId, 'branch_id' => $data['branch_id'], 'contact_id' => $data['contact_id'],
                'quotation_no' => 'QT-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)), 'status' => 'draft',
                'quotation_date' => $data['quotation_date'] ?? today(), 'valid_until' => $data['valid_until'] ?? null,
                'subtotal' => $subtotal, 'discount' => $discount, 'tax' => $tax, 'total' => $total,
                'notes' => $data['notes'] ?? null, 'created_by' => $actorId,
            ]);
            foreach ($data['lines'] as $line) {
                $quotation->lines()->create($this->linePayload($line));
            }
            $this->audit->log($tenantId, $actorId, 'sales_quotation.created', 'sales_quotation', $quotation->id, null, $quotation->toArray());

            return $quotation->load('lines');
        });
    }

    public function transition(SalesQuotation $quotation, string $status, int $actorId): SalesQuotation
    {
        $allowed = ['draft' => ['sent'], 'sent' => ['accepted', 'rejected', 'expired']];
        abort_unless(in_array($status, $allowed[$quotation->status] ?? [], true), 422, 'Invalid quotation transition.');
        $before = $quotation->toArray();
        $quotation->update(['status' => $status]);
        $this->audit->log($quotation->tenant_id, $actorId, 'sales_quotation.'.$status, 'sales_quotation', $quotation->id, $before, $quotation->fresh()->toArray());

        return $quotation->fresh();
    }

    public function convertToProforma(SalesQuotation $quotation, int $actorId, ?string $dueDate = null): ProformaInvoice
    {
        abort_unless(in_array($quotation->status, ['sent', 'accepted'], true), 422, 'Only sent or accepted quotation can be converted.');

        return DB::transaction(function () use ($quotation, $actorId, $dueDate) {
            $quotation = SalesQuotation::withoutGlobalScopes()->lockForUpdate()->findOrFail($quotation->id);
            abort_if($quotation->proformas()->exists(), 422, 'Quotation has already been converted to proforma.');
            $quotation->load('lines');
            $proforma = ProformaInvoice::withoutGlobalScopes()->create([
                'tenant_id' => $quotation->tenant_id, 'branch_id' => $quotation->branch_id, 'contact_id' => $quotation->contact_id,
                'sales_quotation_id' => $quotation->id, 'proforma_no' => 'PF-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'status' => 'issued', 'issue_date' => today(), 'due_date' => $dueDate,
                'subtotal' => $quotation->subtotal, 'discount' => $quotation->discount, 'tax' => $quotation->tax,
                'total' => $quotation->total, 'notes' => $quotation->notes, 'created_by' => $actorId,
            ]);
            foreach ($quotation->lines as $line) {
                $proforma->lines()->create($line->only(['product_variant_id', 'description', 'quantity', 'unit_price', 'discount']));
            }
            $this->audit->log($quotation->tenant_id, $actorId, 'proforma.created_from_quotation', 'proforma_invoice', $proforma->id, null, $proforma->toArray());

            return $proforma->load('lines');
        });
    }

    private function validateReferences(int $tenantId, int $branchId, int $contactId, array $lines): void
    {
        abort_unless(Branch::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($branchId)->exists(), 422, 'Branch does not belong to tenant.');
        abort_unless(Contact::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($contactId)->exists(), 422, 'Customer does not belong to tenant.');
        foreach ($lines as $line) {
            abort_unless(ProductVariant::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($line['product_variant_id'])->exists(), 422, 'Variant does not belong to tenant.');
            abort_if((float) $line['quantity'] <= 0 || (float) $line['unit_price'] < 0 || (float) ($line['discount'] ?? 0) < 0, 422, 'Invalid quotation line.');
        }
    }

    private function totals(array $data): array
    {
        $subtotal = collect($data['lines'])->sum(fn ($line) => round((float) $line['quantity'] * (float) $line['unit_price'], 2));
        $lineDiscount = collect($data['lines'])->sum(fn ($line) => (float) ($line['discount'] ?? 0));
        $discount = $lineDiscount + (float) ($data['discount'] ?? 0);
        $tax = (float) ($data['tax'] ?? 0);
        abort_if($discount > $subtotal, 422, 'Discount exceeds subtotal.');

        return [$subtotal, $discount, $tax, round($subtotal - $discount + $tax, 2)];
    }

    private function linePayload(array $line): array
    {
        return ['product_variant_id' => $line['product_variant_id'], 'description' => $line['description'] ?? null, 'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'discount' => $line['discount'] ?? 0];
    }
}
