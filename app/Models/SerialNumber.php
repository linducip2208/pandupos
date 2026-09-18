<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SerialNumber extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'product_variant_id', 'warehouse_id', 'warehouse_location_id', 'inventory_batch_id',
        'serial_number', 'status', 'purchase_id', 'sales_invoice_id',
        'reserved_reference_type', 'reserved_reference_id', 'reserved_at',
    ];

    protected function casts(): array
    {
        return ['reserved_at' => 'datetime'];
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function warehouseLocation()
    {
        return $this->belongsTo(WarehouseLocation::class);
    }

    public function inventoryBatch()
    {
        return $this->belongsTo(InventoryBatch::class);
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function salesInvoice()
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    public function movements()
    {
        return $this->hasMany(StockMovement::class);
    }
}
