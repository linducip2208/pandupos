<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'product_id', 'name', 'sku', 'purchase_price', 'sell_price'];

    protected function casts(): array
    {
        return ['purchase_price' => 'decimal:2', 'sell_price' => 'decimal:2'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
