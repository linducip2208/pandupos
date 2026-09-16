<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'sku', 'barcode', 'category_id', 'brand_id', 'unit_id', 'alert_quantity', 'is_active'];

    protected function casts(): array
    {
        return ['alert_quantity' => 'decimal:3', 'is_active' => 'boolean'];
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }
}
