<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class RepairOrderPart extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'repair_order_id', 'product_variant_id', 'quantity', 'unit_price'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_price' => 'decimal:2'];
    }

    public function order()
    {
        return $this->belongsTo(RepairOrder::class, 'repair_order_id');
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function lineTotal(): float
    {
        return round((float) $this->quantity * (float) $this->unit_price, 2);
    }
}
