<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class BundleItem extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'bundle_product_id', 'component_variant_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    public function bundleProduct()
    {
        return $this->belongsTo(Product::class, 'bundle_product_id');
    }

    public function componentVariant()
    {
        return $this->belongsTo(ProductVariant::class, 'component_variant_id');
    }
}
