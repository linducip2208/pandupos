<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'warehouse_id', 'contact_id', 'ref_no', 'status', 'approval_level', 'requested_by', 'approved_by', 'approved_at', 'total'];

    protected function casts(): array
    {
        return ['total' => 'decimal:2', 'approved_at' => 'datetime'];
    }

    public function lines()
    {
        return $this->hasMany(PurchaseLine::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function goodsReceipts()
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function supplierInvoices()
    {
        return $this->hasMany(SupplierInvoice::class);
    }

    public function returns()
    {
        return $this->hasMany(PurchaseReturn::class);
    }
}
