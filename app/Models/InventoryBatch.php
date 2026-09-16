<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InventoryBatch extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'product_variant_id', 'warehouse_id', 'batch_number',
        'manufactured_at', 'expires_at', 'supplier_id', 'purchase_id',
    ];

    protected function casts(): array
    {
        return ['manufactured_at' => 'date', 'expires_at' => 'date'];
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function movements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function serialNumbers()
    {
        return $this->hasMany(SerialNumber::class);
    }
}
