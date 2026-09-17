<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockCountLine extends Model
{
    protected $fillable = ['stock_count_id', 'product_variant_id', 'expected_quantity', 'counted_quantity', 'variance_quantity'];

    protected function casts(): array
    {
        return ['expected_quantity' => 'decimal:6', 'counted_quantity' => 'decimal:6', 'variance_quantity' => 'decimal:6'];
    }

    public function stockCount()
    {
        return $this->belongsTo(StockCount::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
