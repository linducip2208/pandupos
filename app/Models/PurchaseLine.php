<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseLine extends Model
{
    protected $fillable = ['purchase_id', 'product_variant_id', 'quantity', 'unit_cost'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_cost' => 'decimal:2'];
    }
}
