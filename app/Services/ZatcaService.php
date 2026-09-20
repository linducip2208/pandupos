<?php

namespace App\Services;

use App\Models\SalesInvoice;
use App\Models\ZatcaDocument;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ZATCA e-invoicing artifacts: TLV QR payloads, simplified UBL 2.1 XML,
 * SHA-256 document hashes, credit/debit notes and a reporting ledger.
 * Live portal clearance (production CSIDs) is an explicit boundary: this
 * module produces portal-ready artifacts and records reporting outcomes.
 */
final class ZatcaService
{
    public function __construct(private AuditService $audit) {}

    public function generateFromSalesInvoice(int $tenantId, int $invoiceId, array $seller, ?int $actorId = null): ZatcaDocument
    {
        $invoice = SalesInvoice::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($invoiceId);
        $sellerName = trim((string) ($seller['name'] ?? ''));
        $sellerVat = preg_replace('/\D/', '', (string) ($seller['vat_number'] ?? ''));
        abort_if($sellerName === '', 422, 'Seller name is required.');
        abort_unless(strlen($sellerVat) === 15, 422, 'Saudi VAT numbers are 15 digits.');

        return DB::transaction(function () use ($tenantId, $invoice, $sellerName, $sellerVat, $actorId) {
            $existing = ZatcaDocument::withoutGlobalScopes()->where('tenant_id', $tenantId)
                ->where('source_type', SalesInvoice::class)->where('source_id', $invoice->id)
                ->where('type', ZatcaDocument::INVOICE)->first();
            if ($existing) {
                return $existing;
            }
            $doc = $this->build($tenantId, [
                'type' => ZatcaDocument::INVOICE,
                'source_type' => SalesInvoice::class, 'source_id' => $invoice->id,
                'seller_name' => $sellerName, 'seller_vat' => $sellerVat,
                'buyer_name' => $invoice->contact?->name, 'buyer_vat' => $invoice->contact?->tax_id,
                'total' => (float) $invoice->total, 'vat_total' => (float) $invoice->tax,
                'issued_at' => $invoice->created_at->toIso8601String(),
            ], $actorId);

            return $doc;
        });
    }

    public function issueNote(int $tenantId, int $referencesDocumentId, string $type, float $total, float $vatTotal, ?int $actorId = null): ZatcaDocument
    {
        abort_unless(in_array($type, [ZatcaDocument::CREDIT_NOTE, ZatcaDocument::DEBIT_NOTE], true), 422, 'Invalid note type.');
        $original = ZatcaDocument::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($referencesDocumentId);
        abort_if($original->type !== ZatcaDocument::INVOICE, 422, 'Notes may only reference invoices.');
        $total = round($total, 2);
        $vatTotal = round($vatTotal, 2);
        abort_if($total <= 0 || $vatTotal < 0 || $vatTotal > $total, 422, 'Invalid note amounts.');

        return $this->build($tenantId, [
            'type' => $type, 'references_document_id' => $original->id,
            'seller_name' => $original->seller_name, 'seller_vat' => $original->seller_vat,
            'buyer_name' => $original->buyer_name, 'buyer_vat' => $original->buyer_vat,
            'total' => $total, 'vat_total' => $vatTotal,
            'issued_at' => now()->toIso8601String(),
        ], $actorId);
    }

    /** Record a portal reporting outcome (clearance id from the live portal). */
    public function markReported(ZatcaDocument $document, string $clearanceId, ?int $actorId = null): ZatcaDocument
    {
        $locked = ZatcaDocument::withoutGlobalScopes()->lockForUpdate()->findOrFail($document->id);
        abort_if($locked->status === 'reported', 422, 'Document is already reported.');
        $clearanceId = trim($clearanceId);
        abort_if($clearanceId === '', 422, 'Clearance id is required.');
        $before = $locked->toArray();
        $locked->update(['status' => 'reported', 'clearance_id' => $clearanceId, 'reported_at' => now()]);
        $this->audit->log($locked->tenant_id, $actorId, 'zatca.reported', ZatcaDocument::class, $locked->id, $before, $locked->fresh()->toArray());

        return $locked->fresh();
    }

    /** ZATCA TLV QR: tags 1 seller, 2 VAT, 3 timestamp, 4 total, 5 VAT. */
    public function buildTlv(string $seller, string $vat, string $timestamp, float $total, float $vatTotal): string
    {
        $fields = [
            1 => $seller, 2 => $vat, 3 => $timestamp,
            4 => number_format($total, 2, '.', ''), 5 => number_format($vatTotal, 2, '.', ''),
        ];
        $bin = '';
        foreach ($fields as $tag => $value) {
            $bin .= chr($tag).chr(strlen($value)).$value;
        }

        return base64_encode($bin);
    }

    /** @return array<int, array{tag:int,value:string}> */
    public static function decodeTlv(string $base64): array
    {
        $bin = base64_decode($base64, true);
        abort_if($bin === false, 422, 'Invalid TLV payload.');
        $out = [];
        $i = 0;
        while ($i < strlen($bin)) {
            abort_if($i + 2 > strlen($bin), 422, 'Truncated TLV payload.');
            $tag = ord($bin[$i]);
            $len = ord($bin[$i + 1]);
            abort_if($i + 2 + $len > strlen($bin), 422, 'Truncated TLV value.');
            $out[] = ['tag' => $tag, 'value' => substr($bin, $i + 2, $len)];
            $i += 2 + $len;
        }

        return $out;
    }

    public function qrSvg(string $tlvBase64, int $size = 200): string
    {
        $renderer = new ImageRenderer(new RendererStyle($size), new SvgImageBackEnd);
        $writer = new Writer($renderer);

        return $writer->writeString($tlvBase64);
    }

    /** Simplified UBL 2.1 invoice with the fields ZATCA requires. */
    public function buildXml(array $doc): string
    {
        $e = fn (?string $v): string => htmlspecialchars($v ?? '', ENT_XML1);
        $typeCode = $doc['type'] === ZatcaDocument::INVOICE ? '388' : ($doc['type'] === ZatcaDocument::CREDIT_NOTE ? '381' : '383');

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'."\n"
            ."  <cbc:UUID>{$e($doc['uuid'])}</cbc:UUID>\n"
            ."  <cbc:InvoiceTypeCode>{$typeCode}</cbc:InvoiceTypeCode>\n"
            .'  <cbc:IssueDate>'.substr($doc['issued_at'], 0, 10)."</cbc:IssueDate>\n"
            ."  <cac:AccountingSupplierParty><cac:Party><cac:PartyName><cbc:Name>{$e($doc['seller_name'])}</cbc:Name></cac:PartyName>"
            ."<cac:PartyTaxScheme><cbc:CompanyID>{$e($doc['seller_vat'])}</cbc:CompanyID><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:PartyTaxScheme></cac:Party></cac:AccountingSupplierParty>\n"
            ."  <cac:AccountingCustomerParty><cac:Party><cac:PartyName><cbc:Name>{$e($doc['buyer_name'] ?? '')}</cbc:Name></cac:PartyName></cac:Party></cac:AccountingCustomerParty>\n"
            .'  <cac:LegalMonetaryTotal><cbc:PayableAmount currencyID="SAR">'.number_format($doc['total'], 2, '.', '')."</cbc:PayableAmount></cac:LegalMonetaryTotal>\n"
            .'  <cac:TaxTotal><cbc:TaxAmount currencyID="SAR">'.number_format($doc['vat_total'], 2, '.', '')."</cbc:TaxAmount></cac:TaxTotal>\n"
            .'</Invoice>';
    }

    private function build(int $tenantId, array $data, ?int $actorId): ZatcaDocument
    {
        $tlv = $this->buildTlv($data['seller_name'], $data['seller_vat'], $data['issued_at'], $data['total'], $data['vat_total']);
        $uuid = (string) Str::uuid();
        // Hash covers the canonical artifact tuple so any drift is detectable.
        $hash = hash('sha256', implode('|', [$uuid, $data['type'], $data['seller_vat'], $data['issued_at'], number_format($data['total'], 2, '.', ''), number_format($data['vat_total'], 2, '.', '')]));
        $xml = $this->buildXml(array_merge($data, ['uuid' => $uuid]));
        $doc = ZatcaDocument::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'uuid' => $uuid, 'type' => $data['type'],
            'source_type' => $data['source_type'] ?? null, 'source_id' => $data['source_id'] ?? null,
            'references_document_id' => $data['references_document_id'] ?? null,
            'seller_name' => $data['seller_name'], 'seller_vat' => $data['seller_vat'],
            'buyer_name' => $data['buyer_name'] ?? null, 'buyer_vat' => $data['buyer_vat'] ?? null,
            'total' => $data['total'], 'vat_total' => $data['vat_total'], 'issued_at' => $data['issued_at'],
            'qr_tlv' => $tlv, 'xml' => $xml, 'hash' => $hash, 'status' => 'generated', 'created_by' => $actorId,
        ]);
        $this->audit->log($tenantId, $actorId, 'zatca.generated', ZatcaDocument::class, $doc->id, null, ['uuid' => $uuid, 'type' => $data['type']]);

        return $doc;
    }
}
