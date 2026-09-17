<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InventoryBalance extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'warehouse_id', 'product_variant_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6'];
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
