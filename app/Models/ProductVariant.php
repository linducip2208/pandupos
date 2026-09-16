<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'product_id', 'name', 'sku', 'barcode', 'attributes', 'purchase_price', 'sell_price'];

    protected function casts(): array
    {
        return ['attributes' => 'array', 'purchase_price' => 'decimal:2', 'sell_price' => 'decimal:2'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function priceListItems()
    {
        return $this->hasMany(PriceListItem::class);
    }

    public function usedInBundles()
    {
        return $this->hasMany(BundleItem::class, 'component_variant_id');
    }
}
