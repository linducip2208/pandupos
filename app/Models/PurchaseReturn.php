<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PurchaseReturn extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'purchase_id', 'supplier_id', 'warehouse_id', 'return_no', 'idempotency_key', 'status', 'subtotal', 'tax', 'discount', 'total', 'settlement_type', 'reason', 'created_by', 'requested_by', 'reviewed_by', 'reviewed_at', 'approved_by', 'approved_at', 'posted_at'];

    protected function casts(): array
    {
        return ['subtotal' => 'decimal:2', 'tax' => 'decimal:2', 'discount' => 'decimal:2', 'total' => 'decimal:2', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime', 'posted_at' => 'datetime'];
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines()
    {
        return $this->hasMany(PurchaseReturnLine::class);
    }
}
