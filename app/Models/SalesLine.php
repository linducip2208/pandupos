<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesLine extends Model
{
    protected $fillable = ['sales_invoice_id', 'product_variant_id', 'quantity', 'unit_price', 'discount'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'discount' => 'decimal:2'];
    }

    public function invoice()
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function returnLines()
    {
        return $this->hasMany(SalesReturnLine::class);
    }
}
