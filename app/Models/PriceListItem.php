<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceListItem extends Model
{
    protected $fillable = ['price_list_id', 'product_variant_id', 'price', 'minimum_quantity'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'minimum_quantity' => 'decimal:3'];
    }

    public function priceList()
    {
        return $this->belongsTo(PriceList::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
