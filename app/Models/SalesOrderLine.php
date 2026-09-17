<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesOrderLine extends Model
{
    protected $fillable = ['sales_order_id', 'product_variant_id', 'quantity', 'fulfilled_quantity', 'unit_price', 'discount'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'fulfilled_quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'discount' => 'decimal:2'];
    }

    public function order()
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function deliveryLines()
    {
        return $this->hasMany(SalesDeliveryLine::class);
    }

    public function reservation()
    {
        return $this->hasOne(StockReservation::class, 'source_id')->where('source_type', 'sales_order_line');
    }
}
