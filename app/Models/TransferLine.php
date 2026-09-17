<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransferLine extends Model
{
    protected $fillable = ['transfer_order_id', 'product_variant_id', 'quantity', 'received_quantity', 'unit_cost'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'received_quantity' => 'decimal:3', 'unit_cost' => 'decimal:4'];
    }

    public function transferOrder()
    {
        return $this->belongsTo(TransferOrder::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
