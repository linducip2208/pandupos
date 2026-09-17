<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProformaInvoiceLine extends Model
{
    protected $fillable = ['proforma_invoice_id', 'product_variant_id', 'description', 'quantity', 'unit_price', 'discount'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'discount' => 'decimal:2'];
    }

    public function proforma()
    {
        return $this->belongsTo(ProformaInvoice::class, 'proforma_invoice_id');
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
