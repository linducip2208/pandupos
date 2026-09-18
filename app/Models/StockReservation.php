<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class StockReservation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'warehouse_id', 'warehouse_location_id', 'product_variant_id', 'inventory_batch_id',
        'quantity', 'source_type', 'source_id', 'idempotency_key', 'status', 'expires_at', 'released_at', 'consumed_at',
        'consumed_reference_type', 'consumed_reference_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6', 'expires_at' => 'datetime',
            'released_at' => 'datetime', 'consumed_at' => 'datetime',
        ];
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function warehouseLocation()
    {
        return $this->belongsTo(WarehouseLocation::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function inventoryBatch()
    {
        return $this->belongsTo(InventoryBatch::class);
    }
}
