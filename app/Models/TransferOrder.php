<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TransferOrder extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'from_warehouse_id', 'to_warehouse_id', 'status'];

    public function fromWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function lines()
    {
        return $this->hasMany(TransferLine::class);
    }
}
