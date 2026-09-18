<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockAdjustmentLine extends Model
{
    protected $fillable = ['stock_adjustment_id', 'product_variant_id', 'inventory_batch_id', 'serial_number_id', 'warehouse_location_id', 'quantity_change', 'unit_cost'];

    protected function casts(): array
    {
        return ['quantity_change' => 'decimal:6', 'unit_cost' => 'decimal:4'];
    }

    public function adjustment()
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function inventoryBatch()
    {
        return $this->belongsTo(InventoryBatch::class);
    }

    public function serialNumber()
    {
        return $this->belongsTo(SerialNumber::class);
    }

    public function warehouseLocation()
    {
        return $this->belongsTo(WarehouseLocation::class);
    }
}
