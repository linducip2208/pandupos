<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PurchaseReturn extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'purchase_id', 'return_no', 'status', 'total', 'settlement_type', 'reason', 'created_by', 'posted_at'];

    protected function casts(): array
    {
        return ['total' => 'decimal:2', 'posted_at' => 'datetime'];
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
