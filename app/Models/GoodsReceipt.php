<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class GoodsReceipt extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'purchase_id', 'warehouse_id', 'receipt_no', 'received_at', 'received_by', 'notes'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function lines()
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }
}
