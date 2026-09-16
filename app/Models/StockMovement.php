<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'warehouse_id', 'product_variant_id', 'inventory_batch_id', 'serial_number_id', 'reference_type',
        'reference_id', 'movement_type', 'quantity', 'unit_cost', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_cost' => 'decimal:2', 'occurred_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        // Append-only ledger: block updates/deletes at model layer (DB has no UPDATE/DELETE path).
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
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
}
