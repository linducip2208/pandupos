<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TransferOrder extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'from_warehouse_id', 'to_warehouse_id', 'status', 'requested_by', 'approved_by', 'shipped_by',
        'approved_at', 'shipped_at', 'in_transit_at', 'received_at', 'cancelled_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime', 'shipped_at' => 'datetime', 'in_transit_at' => 'datetime',
            'received_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

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

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function shipper()
    {
        return $this->belongsTo(User::class, 'shipped_by');
    }
}
