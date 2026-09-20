<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'name', 'product_type', 'sku', 'barcode', 'image_path',
        'category_id', 'brand_id', 'unit_id', 'alert_quantity', 'tax_rate',
        'tax_method', 'track_inventory', 'is_active', 'is_online',
    ];

    protected function casts(): array
    {
        return [
            'alert_quantity' => 'decimal:3',
            'tax_rate' => 'decimal:4',
            'track_inventory' => 'boolean',
            'is_active' => 'boolean',
            'is_online' => 'boolean',
        ];
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function locations()
    {
        return $this->hasMany(ProductLocation::class);
    }

    public function bundleItems()
    {
        return $this->hasMany(BundleItem::class, 'bundle_product_id');
    }
}
