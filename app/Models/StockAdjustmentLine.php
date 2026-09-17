<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockAdjustmentLine extends Model
{
    protected $fillable = ['stock_adjustment_id', 'product_variant_id', 'quantity_change', 'unit_cost'];

    protected function casts(): array
    {
        return ['quantity_change' => 'decimal:6', 'unit_cost' => 'decimal:4'];
    }

    public function adjustment()
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
