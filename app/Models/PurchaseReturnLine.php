<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReturnLine extends Model
{
    protected $fillable = ['purchase_return_id', 'purchase_line_id', 'product_variant_id', 'quantity', 'unit_cost', 'line_total'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_cost' => 'decimal:2', 'line_total' => 'decimal:2'];
    }

    public function purchaseReturn()
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function purchaseLine()
    {
        return $this->belongsTo(PurchaseLine::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
