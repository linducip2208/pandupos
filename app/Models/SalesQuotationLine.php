<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesQuotationLine extends Model
{
    protected $fillable = ['sales_quotation_id', 'product_variant_id', 'description', 'quantity', 'unit_price', 'discount'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'discount' => 'decimal:2'];
    }

    public function quotation()
    {
        return $this->belongsTo(SalesQuotation::class, 'sales_quotation_id');
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
