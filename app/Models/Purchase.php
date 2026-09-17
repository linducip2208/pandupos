<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'warehouse_id', 'contact_id', 'ref_no', 'status', 'total'];

    protected function casts(): array
    {
        return ['total' => 'decimal:2'];
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
