<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ZatcaDocument extends Model
{
    use BelongsToTenant;

    public const INVOICE = 'invoice';

    public const CREDIT_NOTE = 'credit_note';

    public const DEBIT_NOTE = 'debit_note';

    protected $fillable = [
        'tenant_id', 'uuid', 'type', 'source_id', 'source_type', 'references_document_id',
        'seller_name', 'seller_vat', 'buyer_name', 'buyer_vat', 'total', 'vat_total',
        'issued_at', 'qr_tlv', 'xml', 'hash', 'status', 'clearance_id', 'reported_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2', 'vat_total' => 'decimal:2',
            'issued_at' => 'datetime', 'reported_at' => 'datetime',
        ];
    }

    public function referenced()
    {
        return $this->belongsTo(ZatcaDocument::class, 'references_document_id');
    }
}
