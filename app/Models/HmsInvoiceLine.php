<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class HmsInvoiceLine extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'hms_invoice_id', 'product_variant_id', 'quantity', 'unit_price'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_price' => 'decimal:2'];
    }

    public function invoice()
    {
        return $this->belongsTo(HmsInvoice::class, 'hms_invoice_id');
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
